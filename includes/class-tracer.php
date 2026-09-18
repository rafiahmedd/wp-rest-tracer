<?php
/**
 * Core tracing engine.
 *
 * On a matching REST request this class:
 *  - brackets the whole request (rest_pre_dispatch → rest_post_dispatch),
 *  - transparently wraps every hook/filter callback registered in $wp_filter
 *    (and lazily wraps callbacks registered later, via the "all" hook),
 *  - records SQL queries (via the "query" filter + wpdb timing) and outbound
 *    HTTP requests (pre_http_request + http_api_debug),
 *  - brackets the matched REST handler (rest_request_before/after_callbacks),
 *  - builds a flat node list describing the nested call tree with inclusive
 *    timings, and stores it via REST_Tracer_Storage.
 *
 * Node format (after finalize): [ parentIndex, label, type, startMs, durMs, meta|null ].
 *
 * @package REST_Tracer
 */

defined( 'ABSPATH' ) || exit;

final class REST_Tracer_Tracer {

	/** @var REST_Tracer_Tracer|null */
	private static $instance = null;

	/** @var bool Trace session running. */
	private $active    = false;
	private $finalized = false;
	private $truncated = false;

	/** @var array[] Flat node store: parent, label, type, start, end, meta. */
	private $nodes = array();

	/** @var int[] Stack of open node indexes (hierarchical context). */
	private $stack = array();

	/** @var int|null Node index of the open REST handler bracket. */
	private $handler_node = null;

	/** @var int|null Node index of the open (executing) SQL query. */
	private $open_query_node = null;

	/** @var int|null Node index of the open (executing) HTTP request. */
	private $open_http_node = null;

	/** @var SplObjectStorage Wrapper closure => [ tag, priority, idx, original ]. */
	private $our_closures;

	/** @var bool Whether the "all" hook listener is attached. */
	private $hooked_all = false;

	/** @var array[] Our own internal hook registrations [ tag, type, callback, priority, args ]. */
	private $our_listeners = array();

	/** @var float microtime(true) at trace start. */
	private $start_time = 0.0;

	/** @var int Hard cap on recorded nodes. */
	private $max_nodes = 10000;

	/** @var bool|null Previous $wpdb->save_queries value. */
	private $prev_save_queries = null;

	/** @var string Matched watched namespace. */
	private $namespace = '';

	/** @var object|null The REST server instance, for endpoint callback wrapping. */
	private $rest_server = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->our_closures = new SplObjectStorage();
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_start' ), - PHP_INT_MAX, 3 );
	}

	private function __clone() {
	}

	/**
	 * rest_pre_dispatch: decide whether to trace this request.
	 *
	 * @param mixed            $result  Always null at this stage.
	 * @param WP_REST_Server   $server  REST server.
	 * @param WP_REST_Request  $request Current request.
	 * @return mixed Always $result.
	 */
	public function maybe_start( $result, $server, $request ) {
		if ( $this->active || ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		$route   = (string) $request->get_route();
		$matched = '';
		foreach ( rest_tracer_watched_namespaces() as $ns ) {
			if ( rest_tracer_namespace_matches( $route, $ns ) ) {
				$matched = $ns;
				break;
			}
		}
		if ( '' === $matched ) {
			return $result;
		}

		$settings   = REST_Tracer_Plugin::get_settings();
		$header_ok  = $this->token_ok();
		// The admin API namespace is only ever traced with an explicit header
		// so that "Always" mode doesn't record the tracer's own UI requests.
		$always     = ( 'always' === $settings['mode'] ) && 'rest-tracer/v1' !== $matched;

		if ( ! $always && ! $header_ok ) {
			return $result;
		}

		$this->start( $request, $matched, $server );

		return $result;
	}

	/**
	 * Validate the X-REST-Tracer trigger header against the stored token.
	 *
	 * @return bool
	 */
	private function token_ok() {
		$settings = REST_Tracer_Plugin::get_settings();
		$token    = $settings['token'];
		$given    = isset( $_SERVER['HTTP_X_REST_TRACER'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REST_TRACER'] ) )
			: '';
		if ( '' === $token || '' === $given ) {
			return false;
		}
		return hash_equals( (string) $token, $given );
	}

	/**
	 * Start a trace session.
	 *
	 * @param WP_REST_Request $request   Current request.
	 * @param string          $namespace Matched namespace.
	 * @param object|null     $server    REST server instance.
	 */
	private function start( $request, $namespace, $server = null ) {
		global $wpdb;

		$settings = REST_Tracer_Plugin::get_settings();

		// Reset per-request state — a process may trace more than one REST
		// request (e.g. internal rest_do_request calls).
		$this->active            = true;
		$this->finalized         = false;
		$this->truncated         = false;
		$this->namespace         = $namespace;
		$this->start_time        = microtime( true );
		$this->max_nodes         = max( 500, (int) $settings['max_nodes'] );
		$this->nodes             = array();
		$this->stack             = array();
		$this->handler_node      = null;
		$this->open_query_node   = null;
		$this->open_http_node    = null;
		$this->rest_server       = null;
		$this->our_closures      = new SplObjectStorage();

		$this->prev_save_queries = ! empty( $wpdb->save_queries );
		if ( $settings['capture_sql'] ) {
			$wpdb->save_queries = true;
		}

		// Root node: the whole request.
		$this->nodes[] = array(
			'parent' => null,
			'label'  => 'REST ' . strtoupper( (string) $request->get_method() ) . ' ' . $request->get_route(),
			'type'   => 'request',
			'start'  => $this->start_time,
			'end'    => null,
			'meta'   => array(
				'namespace' => $namespace,
				'route'     => (string) $request->get_route(),
				'method'    => strtoupper( (string) $request->get_method() ),
				'user'      => get_current_user_id(),
				'params'    => rest_tracer_sanitize_params( $request->get_params() ),
			),
		);
		$this->stack = array( 0 );

		$this->add_internal_hooks( $settings );

		// Wrap the matched endpoint's callback + permission callback so the
		// actual handler (closure or controller method) is timed and named,
		// even when registered behind a delegating wrapper like Route::callback.
		$this->wrap_endpoint_callbacks( $server );

		if ( $settings['capture_hooks'] ) {
			$this->wrap_existing_hooks();
			add_action( 'all', array( $this, 'on_all' ), - PHP_INT_MAX );
			$this->hooked_all = true;
		}

		register_shutdown_function( array( $this, 'finalize' ) );
	}

	/**
	 * Attach the tracer's own event listeners.
	 *
	 * @param array $settings Plugin settings.
	 */
	private function add_internal_hooks( $settings ) {
		$this->our_listeners = array(
			array( 'rest_request_before_callbacks', 'filter', array( $this, 'on_before_callbacks' ), - PHP_INT_MAX, 3 ),
			array( 'rest_request_after_callbacks', 'filter', array( $this, 'on_after_callbacks' ), PHP_INT_MAX, 3 ),
			array( 'rest_post_dispatch', 'filter', array( $this, 'on_post_dispatch' ), PHP_INT_MAX, 3 ),
		);

		if ( $settings['capture_sql'] ) {
			$this->our_listeners[] = array( 'query', 'filter', array( $this, 'on_query' ), - PHP_INT_MAX, 1 );
		}
		if ( $settings['capture_http'] ) {
			$this->our_listeners[] = array( 'pre_http_request', 'filter', array( $this, 'on_pre_http' ), PHP_INT_MAX, 3 );
			$this->our_listeners[] = array( 'http_api_debug', 'action', array( $this, 'on_http_debug' ), PHP_INT_MAX, 5 );
		}

		foreach ( $this->our_listeners as $l ) {
			if ( 'filter' === $l[1] ) {
				add_filter( $l[0], $l[2], $l[3], $l[4] );
			} else {
				add_action( $l[0], $l[2], $l[3], $l[4] );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Hook callback wrapping
	 * ------------------------------------------------------------------- */

	/**
	 * Wrap every callback currently registered in $wp_filter.
	 */
	private function wrap_existing_hooks() {
		global $wp_filter;
		foreach ( array_keys( $wp_filter ) as $tag ) {
			$this->wrap_tag( $tag );
		}
	}

	/**
	 * "all" hook: fired before every do_action/apply_filters. Lazily wrap any
	 * callbacks that were registered after the trace started.
	 *
	 * @param string $hook_name Hook tag about to execute.
	 */
	public function on_all( $hook_name ) {
		if ( ! $this->active || $this->finalized || ! is_string( $hook_name ) || 'all' === $hook_name ) {
			return;
		}
		$this->wrap_tag( $hook_name );
	}

	/**
	 * Whether a callback belongs to the tracer itself.
	 *
	 * @param mixed $fn Callable.
	 * @return bool
	 */
	private function is_our_callback( $fn ) {
		if ( $fn instanceof Closure ) {
			return isset( $this->our_closures[ $fn ] );
		}
		if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && $fn[0] === $this ) {
			return true;
		}
		return $fn === $this;
	}

	/**
	 * Wrap all callbacks registered on one hook tag.
	 *
	 * @param string $tag Hook tag.
	 */
	private function wrap_tag( $tag ) {
		global $wp_filter;

		if ( ! is_string( $tag ) || '' === $tag || 'all' === $tag ) {
			return;
		}
		if ( ! isset( $wp_filter[ $tag ] ) || ! $wp_filter[ $tag ] instanceof WP_Hook ) {
			return;
		}

		foreach ( $wp_filter[ $tag ]->callbacks as $priority => $by_idx ) {
			foreach ( $by_idx as $idx => $cb ) {
				if ( empty( $cb['function'] ) ) {
					continue;
				}
				$fn = $cb['function'];
				if ( $this->is_our_callback( $fn ) || ( $fn instanceof Closure && isset( $this->our_closures[ $fn ] ) ) ) {
					continue;
				}

				$label   = $tag . ' → ' . rest_tracer_render_callable( $fn );
				$wrapper = $this->make_wrapper( $tag, $fn, $label );

				$this->our_closures[ $wrapper ] = array( $tag, $priority, $idx, $fn );
				$wp_filter[ $tag ]->callbacks[ $priority ][ $idx ]['function'] = $wrapper;
			}
		}
	}

	/**
	 * Build the timing wrapper for a single hook callback.
	 *
	 * @param string   $tag      Hook tag.
	 * @param callable $original Original callback.
	 * @param string   $label    Node label.
	 * @return Closure
	 */
	private function make_wrapper( $tag, $original, $label ) {
		$tracer = $this;
		return function ( ...$args ) use ( $tracer, $original, $label, $tag ) {
			return $tracer->trace_call( $label, 'hook', $original, $args, array( 'hook' => $tag ) );
		};
	}

	/* ---------------------------------------------------------------------
	 * Endpoint callback wrapping
	 * ------------------------------------------------------------------- */

	/**
	 * Wrap every registered endpoint's callback and permission_callback so the
	 * actual handler appears as its own timed node inside the handler bracket.
	 *
	 * Frameworks commonly register a delegating method (e.g. Route::callback)
	 * whose real handler is resolved lazily during the request; the wrapper
	 * re-renders the label after execution, once the delegate is resolvable.
	 *
	 * @param object|null $server REST server instance (duck-typed: needs ->endpoints).
	 */
	private function wrap_endpoint_callbacks( $server ) {
		if ( ! is_object( $server ) || empty( $server->endpoints ) || ! is_array( $server->endpoints ) ) {
			return;
		}

		$this->rest_server = $server;

		foreach ( $server->endpoints as $route => $handlers ) {
			if ( ! is_string( $route ) || ! is_array( $handlers ) ) {
				continue;
			}
			foreach ( $handlers as $hidx => $handler ) {
				if ( ! is_array( $handler ) ) {
					continue;
				}
				foreach ( array( 'callback' => 'handler', 'permission_callback' => 'permission' ) as $key => $kind ) {
					if ( empty( $handler[ $key ] ) || ! is_callable( $handler[ $key ] ) ) {
						continue;
					}
					if ( $this->is_our_callback( $handler[ $key ] ) ) {
						continue;
					}

					$wrapper = $this->make_endpoint_wrapper( $handler[ $key ], $kind );

					// Registry slot format matches hook entries; idx carries the
					// endpoint location so teardown can restore the original.
					$this->our_closures[ $wrapper ] = array( '__endpoint__', 0, array( $route, $hidx, $key ), $handler[ $key ] );

					$server->endpoints[ $route ][ $hidx ][ $key ] = $wrapper;
				}
			}
		}
	}

	/**
	 * Build the timing wrapper for an endpoint callback / permission callback.
	 *
	 * @param callable $original Original callback.
	 * @param string   $kind     'handler' or 'permission'.
	 * @return Closure
	 */
	private function make_endpoint_wrapper( $original, $kind ) {
		$tracer  = $this;
		$type    = ( 'permission' === $kind ) ? 'permission' : 'handler';
		$prefix  = ( 'permission' === $kind ) ? 'Permission ' : 'Callback ';

		return function ( ...$args ) use ( $tracer, $original, $type, $prefix ) {
			$idx = $tracer->begin_node( $prefix . rest_tracer_render_callable( $original ), $type, null );

			try {
				$result = call_user_func_array( $original, $args );
			} catch ( Throwable $e ) {
				if ( $idx >= 0 ) {
					$tracer->finish_node(
						$idx,
						null,
						null,
						array( 'exception' => get_class( $e ) . ': ' . $e->getMessage() )
					);
				}
				throw $e;
			}

			if ( $idx >= 0 ) {
				// Re-resolve now that lazy frameworks have parsed their handlers.
				$tracer->finish_node( $idx, null, $prefix . rest_tracer_render_callable( $original ), null );
			}
			return $result;
		};
	}

	/* ---------------------------------------------------------------------
	 * Node bookkeeping
	 * ------------------------------------------------------------------- */

	/**
	 * Public two-phase node API (used by endpoint wrappers): open a node.
	 *
	 * @param string     $label Node label.
	 * @param string     $type  Node type.
	 * @param array|null $meta  Extra metadata.
	 * @return int Node index, or -1 when not recording.
	 */
	public function begin_node( $label, $type, $meta = null ) {
		if ( ! $this->active || $this->finalized ) {
			return - 1;
		}
		$this->close_open_query();
		return $this->push_node( $label, $type, microtime( true ), $meta );
	}

	/**
	 * Public two-phase node API: optionally refine the label/meta, then close
	 * the node and pop the stack down to it.
	 *
	 * @param int        $idx        Node index.
	 * @param float|null $t1         End timestamp; defaults to now.
	 * @param string|null $label     Replacement label.
	 * @param array|null $extra_meta Extra meta merged into the node.
	 */
	public function finish_node( $idx, $t1 = null, $label = null, $extra_meta = null ) {
		if ( $idx < 0 || ! isset( $this->nodes[ $idx ] ) ) {
			return;
		}
		if ( null !== $label ) {
			$this->nodes[ $idx ]['label'] = (string) $label;
		}
		$this->stack_close_to( $idx, $t1, $extra_meta );
	}

	/**
	 * Push a node (as a child of the current stack top) onto the tree and stack.
	 *
	 * @param string     $label Node label.
	 * @param string     $type  Node type: request|handler|hook|query|http.
	 * @param float      $t0    Start timestamp (microtime).
	 * @param array|null $meta  Extra metadata.
	 * @return int Node index, or -1 when the node cap was reached.
	 */
	private function push_node( $label, $type, $t0, $meta = null ) {
		if ( count( $this->nodes ) >= $this->max_nodes ) {
			$this->truncated = true;
			return - 1;
		}
		$this->nodes[] = array(
			'parent' => empty( $this->stack ) ? null : $this->stack[ count( $this->stack ) - 1 ],
			'label'  => (string) $label,
			'type'   => (string) $type,
			'start'  => $t0,
			'end'    => null,
			'meta'   => $meta,
		);
		$idx = count( $this->nodes ) - 1;
		$this->stack[] = $idx;
		return $idx;
	}

	/**
	 * Pop the stack down to (and including) $idx, closing every node popped.
	 *
	 * @param int        $idx        Node index to close.
	 * @param float|null $t1         End timestamp; defaults to now.
	 * @param array|null $extra_meta Extra meta merged into the target node.
	 */
	private function stack_close_to( $idx, $t1 = null, $extra_meta = null ) {
		$t1 = ( null === $t1 ) ? microtime( true ) : $t1;
		while ( ! empty( $this->stack ) ) {
			$top = array_pop( $this->stack );
			$this->close_node( $top, $t1, ( $top === $idx ) ? $extra_meta : null );
			if ( $top === $idx ) {
				return;
			}
		}
	}

	/**
	 * Close a node.
	 *
	 * @param int        $idx        Node index.
	 * @param float      $t1         End timestamp.
	 * @param array|null $extra_meta Extra meta merged into the node.
	 */
	private function close_node( $idx, $t1, $extra_meta = null ) {
		if ( $idx < 0 || ! isset( $this->nodes[ $idx ] ) ) {
			return;
		}
		if ( null !== $this->nodes[ $idx ]['end'] ) {
			return;
		}
		$this->nodes[ $idx ]['end'] = $t1;
		if ( $extra_meta ) {
			$this->nodes[ $idx ]['meta'] = array_merge( (array) $this->nodes[ $idx ]['meta'], (array) $extra_meta );
		}
	}

	/**
	 * Merge extra metadata into a node.
	 *
	 * @param int   $idx  Node index.
	 * @param array $meta Meta to merge.
	 */
	private function append_node_meta( $idx, array $meta ) {
		if ( ! isset( $this->nodes[ $idx ] ) ) {
			return;
		}
		$this->nodes[ $idx ]['meta'] = array_merge( (array) $this->nodes[ $idx ]['meta'], $meta );
	}

	/* ---------------------------------------------------------------------
	 * Event handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Generic wrapper entry point used by every wrapped hook callback.
	 *
	 * @param string   $label    Node label.
	 * @param string   $type     Node type.
	 * @param callable $callback Original callback.
	 * @param array    $args     Arguments.
	 * @param array|null $meta   Extra metadata.
	 * @return mixed Original callback's return value.
	 * @throws Throwable Re-throws after recording.
	 */
	public function trace_call( $label, $type, $callback, array $args, $meta = null ) {
		if ( ! $this->active || $this->finalized ) {
			return call_user_func_array( $callback, $args );
		}

		$this->close_open_query();
		$t0  = microtime( true );
		$idx = $this->push_node( $label, $type, $t0, $meta );

		try {
			$result = call_user_func_array( $callback, $args );
		} catch ( Throwable $e ) {
			$t1 = microtime( true );
			if ( $idx >= 0 ) {
				$this->stack_close_to(
					$idx,
					$t1,
					array( 'exception' => get_class( $e ) . ': ' . $e->getMessage() )
				);
			}
			throw $e;
		}

		$t1 = microtime( true );
		if ( $idx >= 0 ) {
			$this->stack_close_to( $idx, $t1 );
		}
		return $result;
	}

	/**
	 * "query" filter: opens a query node (not pushed onto the stack — a query
	 * cannot contain nested work). Exact duration is resolved on close from
	 * the wpdb query log.
	 *
	 * @param string $sql SQL statement.
	 * @return string
	 */
	public function on_query( $sql ) {
		if ( ! $this->active || $this->finalized ) {
			return $sql;
		}
		$this->close_open_query();

		if ( count( $this->nodes ) >= $this->max_nodes ) {
			$this->truncated = true;
			return $sql;
		}

		global $wpdb;
		$text = (string) $sql;

		$this->nodes[] = array(
			'parent' => empty( $this->stack ) ? null : $this->stack[ count( $this->stack ) - 1 ],
			'label'  => 'SQL: ' . substr( preg_replace( '/\s+/', ' ', $text ), 0, 100 ),
			'type'   => 'query',
			'start'  => microtime( true ),
			'end'    => null,
			'meta'   => array(
				'sql'  => substr( $text, 0, 2000 ),
				// Index this query will occupy in the wpdb query log.
				'qidx' => count( (array) $wpdb->queries ),
			),
		);
		$this->open_query_node = count( $this->nodes ) - 1;

		return $sql;
	}

	/**
	 * Close the open query node, preferring the exact elapsed time recorded by wpdb.
	 */
	private function close_open_query() {
		$idx = $this->open_query_node;
		$this->open_query_node = null;

		if ( null === $idx || ! isset( $this->nodes[ $idx ] ) || null !== $this->nodes[ $idx ]['end'] ) {
			return;
		}

		global $wpdb;
		$node = $this->nodes[ $idx ];
		$end  = max( $node['start'], microtime( true ) );

		$qidx = ( isset( $node['meta']['qidx'] ) ) ? (int) $node['meta']['qidx'] : - 1;
		if ( $qidx >= 0 && isset( $wpdb->queries[ $qidx ] ) && is_array( $wpdb->queries[ $qidx ] ) ) {
			$logged = $wpdb->queries[ $qidx ];
			if ( isset( $logged[1] ) && (float) $logged[1] > 0 ) {
				$end = max( $node['start'], $node['start'] + (float) $logged[1] );
			}
			if ( isset( $logged[2] ) && is_string( $logged[2] ) && '' !== $logged[2] ) {
				$this->append_node_meta( $idx, array( 'caller' => substr( $logged[2], 0, 300 ) ) );
			}
		}

		$this->nodes[ $idx ]['end'] = $end;
	}

	/**
	 * pre_http_request (runs LAST so the short-circuit decision is final).
	 *
	 * @param false|array|WP_Error $pre   Short-circuit value.
	 * @param array                $args  Request args.
	 * @param string               $url   URL.
	 * @return mixed Unchanged $pre.
	 */
	public function on_pre_http( $pre, $args, $url ) {
		if ( ! $this->active || $this->finalized ) {
			return $pre;
		}
		$this->close_open_query();

		$method = ( isset( $args['method'] ) && is_string( $args['method'] ) ) ? $args['method'] : 'GET';

		if ( false !== $pre ) {
			// Another callback short-circuited the transport; record as an instant event.
			$idx = $this->push_node(
				'HTTP ' . $method . ' (short-circuited) ' . $url,
				'http',
				microtime( true ),
				array(
					'url'             => (string) $url,
					'method'          => $method,
					'short_circuited' => true,
				)
			);
			if ( $idx >= 0 ) {
				$this->stack_close_to( $idx, microtime( true ) );
			}
			return $pre;
		}

		$this->open_http_node = $this->push_node(
			'HTTP ' . $method . ' ' . $url,
			'http',
			microtime( true ),
			array(
				'url'    => (string) $url,
				'method' => $method,
			)
		);

		return $pre;
	}

	/**
	 * http_api_debug: closes the open HTTP node with its result.
	 *
	 * @param mixed  $response Response.
	 * @param string $context  Context.
	 * @param string $class    Transport class.
	 * @param array  $args     Request args.
	 * @param string $url      URL.
	 */
	public function on_http_debug( $response, $context, $class, $args, $url ) {
		if ( ! $this->active || $this->finalized ) {
			return;
		}
		$this->close_open_query();

		$idx = $this->open_http_node;
		$this->open_http_node = null;

		if ( null === $idx || ! isset( $this->nodes[ $idx ] ) || null !== $this->nodes[ $idx ]['end'] ) {
			return;
		}

		$meta = array(
			'transport' => is_object( $class ) ? get_class( $class ) : (string) $class,
		);
		if ( is_wp_error( $response ) ) {
			$meta['error'] = $response->get_error_message();
		} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
			$meta['status'] = (int) $response->get_status();
		} elseif ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			$meta['status'] = (int) $response['response']['code'];
		}

		$this->append_node_meta( $idx, $meta );
		$this->stack_close_to( $idx, microtime( true ) );
	}

	/**
	 * Compose the handler bracket label for a matched route handler.
	 *
	 * @param array           $handler Matched route handler.
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function handler_label( $handler, $request ) {
		$methods = array();
		if ( isset( $handler['methods'] ) ) {
			$methods = rest_tracer_http_verbs( $handler['methods'] );
		}
		$callback = isset( $handler['callback'] ) ? $this->original_of( $handler['callback'] ) : null;

		return 'Handler ' . implode( '|', $methods ) . ' ' . $request->get_route()
			. ' → ' . rest_tracer_render_callable( $callback );
	}

	/**
	 * Map one of our wrapper closures back to the original callback.
	 *
	 * @param mixed $callback Callable.
	 * @return mixed
	 */
	private function original_of( $callback ) {
		if ( $callback instanceof Closure && isset( $this->our_closures[ $callback ] ) ) {
			return $this->our_closures[ $callback ][3];
		}
		return $callback;
	}

	/**
	 * rest_request_before_callbacks (runs FIRST): opens the handler node that
	 * brackets permission check + endpoint callback.
	 *
	 * @param mixed           $response Response so far (usually null).
	 * @param array           $handler  Matched route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function on_before_callbacks( $response, $handler, $request ) {
		if ( ! $this->active || $this->finalized ) {
			return $response;
		}
		$this->close_open_query();

		$meta = array(
			'permission' => ( isset( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ) )
				? rest_tracer_render_callable( $this->original_of( $handler['permission_callback'] ) )
				: null,
		);

		$this->handler_node = $this->push_node(
			$this->handler_label( $handler, $request ),
			'handler',
			microtime( true ),
			$meta
		);

		return $response;
	}

	/**
	 * rest_request_after_callbacks (runs LAST): closes the handler node. The
	 * label is re-rendered here because lazy frameworks resolve their real
	 * handler (closure / controller method) during execution.
	 *
	 * @param mixed           $response Endpoint response.
	 * @param array           $handler  Matched route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function on_after_callbacks( $response, $handler, $request ) {
		if ( ! $this->active || $this->finalized ) {
			return $response;
		}
		$this->close_open_query();

		$idx = $this->handler_node;
		$this->handler_node = null;

		if ( null !== $idx && $idx >= 0 ) {
			$meta = array();
			if ( is_wp_error( $response ) ) {
				$meta['error']  = $response->get_error_code();
				$meta['detail'] = $response->get_error_message();
			} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
				$meta['status'] = (int) $response->get_status();
			}
			$this->append_node_meta( $idx, $meta );

			if ( isset( $this->nodes[ $idx ] ) ) {
				$this->nodes[ $idx ]['label'] = $this->handler_label( $handler, $request );
			}
			$this->stack_close_to( $idx, microtime( true ) );
		}

		return $response;
	}

	/**
	 * rest_post_dispatch: the response is complete — finalize the trace.
	 *
	 * @param mixed           $result  Final response.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function on_post_dispatch( $result, $server, $request ) {
		$this->finalize( $result );
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Finalize + teardown
	 * ------------------------------------------------------------------- */

	/**
	 * Close everything, restore the hook system and store the trace.
	 *
	 * @param mixed $response Final response, if any.
	 */
	public function finalize( $response = null ) {
		if ( ! $this->active || $this->finalized ) {
			return;
		}
		$this->finalized = true;
		$end = microtime( true );

		$this->close_open_query();
		if ( null !== $this->open_http_node ) {
			$this->stack_close_to( $this->open_http_node, $end );
			$this->open_http_node = null;
		}
		while ( ! empty( $this->stack ) ) {
			$this->close_node( array_pop( $this->stack ), $end );
		}
		if ( isset( $this->nodes[0] ) ) {
			$this->nodes[0]['end'] = $end;
		}

		$this->teardown();

		if ( empty( $this->nodes ) ) {
			return;
		}

		$t0 = $this->start_time;

		// Serialize nodes compactly: [ parent, label, type, startMs, durMs, meta|null ].
		$nodes_out = array();
		foreach ( $this->nodes as $n ) {
			$nodes_out[] = array(
				$n['parent'],
				(string) $n['label'],
				(string) $n['type'],
				round( ( $n['start'] - $t0 ) * 1000, 3 ),
				round( max( 0, ( ( null !== $n['end'] ? $n['end'] : $end ) - $n['start'] ) ) * 1000, 3 ),
				empty( $n['meta'] ) ? null : $n['meta'],
			);
		}

		// Response summary.
		$response_meta = array();
		$status        = 0;
		if ( is_wp_error( $response ) ) {
			$response_meta['error']   = $response->get_error_code();
			$response_meta['detail']  = $response->get_error_message();
		} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
			$status = (int) $response->get_status();
			$response_meta['status'] = $status;
			if ( method_exists( $response, 'get_data' ) ) {
				$body = wp_json_encode( $response->get_data(), JSON_INVALID_UTF8_SUBSTITUTE );
				$response_meta['body_bytes'] = ( false === $body || null === $body ) ? 0 : strlen( $body );
			}
		}

		$root_meta = (array) $this->nodes[0]['meta'];

		$payload = array(
			'meta'  => array(
				'namespace'   => $this->namespace,
				'started'     => gmdate( 'Y-m-d H:i:s', (int) $t0 ) . ' UTC',
				'duration_ms' => round( ( $end - $t0 ) * 1000, 3 ),
				'peak_memory' => memory_get_peak_usage( true ),
				'truncated'   => $this->truncated,
				'response'    => $response_meta,
				'php'         => PHP_VERSION,
				'wp'          => get_bloginfo( 'version' ),
			),
			'nodes' => $nodes_out,
		);

		$settings = REST_Tracer_Plugin::get_settings();

		REST_Tracer_Storage::insert_trace(
			array(
				'namespace'   => $this->namespace,
				'route'       => isset( $root_meta['route'] ) ? $root_meta['route'] : '',
				'method'      => isset( $root_meta['method'] ) ? $root_meta['method'] : '',
				'status'      => $status,
				'duration_ms' => $payload['meta']['duration_ms'],
				'nodes'       => count( $nodes_out ),
				'peak_memory' => $payload['meta']['peak_memory'],
				'truncated'   => $this->truncated ? 1 : 0,
				'payload'     => $payload,
			),
			(int) $settings['max_traces']
		);

		$this->active = false;
	}

	/**
	 * Restore wrapped callbacks, remove listeners and revert wpdb state.
	 */
	private function teardown() {
		global $wp_filter;

		// Restore wrapped callbacks (skip slots someone else replaced meanwhile).
		$restore = array();
		foreach ( $this->our_closures as $wrapper ) {
			$restore[] = $wrapper;
		}
		foreach ( $restore as $wrapper ) {
			$info = $this->our_closures[ $wrapper ];

			if ( '__endpoint__' === $info[0] ) {
				// Endpoint wrapper: idx slot is array( route, handler index, key ).
				list( $route, $hidx, $key ) = $info[2];
				if ( null !== $this->rest_server
					&& isset( $this->rest_server->endpoints[ $route ][ $hidx ][ $key ] )
					&& $this->rest_server->endpoints[ $route ][ $hidx ][ $key ] === $wrapper ) {
					$this->rest_server->endpoints[ $route ][ $hidx ][ $key ] = $info[3];
				}
			} else {
				$tag = $info[0];
				if ( isset( $wp_filter[ $tag ]->callbacks[ $info[1] ][ $info[2] ] )
					&& $wp_filter[ $tag ]->callbacks[ $info[1] ][ $info[2] ]['function'] === $wrapper ) {
					$wp_filter[ $tag ]->callbacks[ $info[1] ][ $info[2] ]['function'] = $info[3];
				}
			}

			unset( $this->our_closures[ $wrapper ] );
		}

		foreach ( (array) $this->our_listeners as $l ) {
			if ( 'filter' === $l[1] ) {
				remove_filter( $l[0], $l[2], $l[3] );
			} else {
				remove_action( $l[0], $l[2], $l[3] );
			}
		}
		$this->our_listeners = array();

		if ( $this->hooked_all ) {
			remove_action( 'all', array( $this, 'on_all' ), - PHP_INT_MAX );
			$this->hooked_all = false;
		}

		if ( null !== $this->prev_save_queries ) {
			global $wpdb;
			$wpdb->save_queries       = $this->prev_save_queries;
			$this->prev_save_queries  = null;
		}

		$this->rest_server = null;
	}
}
