<?php
/**
 * Shared helper functions.
 *
 * @package REST_Tracer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render a human-readable name for any PHP callable (string, array, Closure, invokable object).
 *
 * When the callback is a delegating method (e.g. a framework Route::callback that invokes
 * $this->app->call($this->action, ...)), the delegate is resolved recursively and appended
 * after an arrow, e.g. "Route::callback → CourseController::index".
 *
 * @param mixed $callback Callable or anything.
 * @param int   $depth    Delegation resolution depth (internal).
 * @return string
 */
function rest_tracer_render_callable( $callback, $depth = 0 ) {
	if ( is_string( $callback ) ) {
		// Framework-style "Class@method" handler strings.
		if ( preg_match( '/^([A-Za-z_][\w\\\\]*)@(\w+)$/', $callback, $m ) ) {
			return ltrim( $m[1], '\\' ) . '::' . $m[2];
		}
		return $callback;
	}

	if ( $callback instanceof Closure ) {
		return rest_tracer_render_closure( $callback, $depth );
	}

	if ( is_array( $callback )
		&& 2 === count( $callback )
		&& ( is_object( $callback[0] ) || is_string( $callback[0] ) )
		&& is_string( $callback[1] ) ) {
		return rest_tracer_render_method( $callback[0], $callback[1], $depth );
	}

	if ( is_object( $callback ) ) {
		return get_class( $callback ) . '::__invoke';
	}

	return '(unknown)';
}

/**
 * Render an object/static method callable, resolving one or more delegation layers.
 *
 * @param object|string $objectOrClass Object or class name.
 * @param string        $method        Method name.
 * @param int           $depth         Depth.
 * @return string
 */
function rest_tracer_render_method( $objectOrClass, $method, $depth = 0 ) {
	static $cache = array();

	$isObj = is_object( $objectOrClass );
	$class = $isObj ? get_class( $objectOrClass ) : (string) $objectOrClass;
	$key   = ( $isObj ? 'o' . spl_object_id( $objectOrClass ) : 'c' . $class ) . '::' . $method . '|' . $depth;

	// Only resolved chains are cached: an early render (before a framework lazily
	// parses its handler) must not poison later renders in the same request.
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$base = $class . '::' . $method;
	$out  = $base;

	if ( $depth < 3 ) {
		$weak = false;
		$chain = rest_tracer_scan_delegate(
			rest_tracer_method_source( $class, $method ),
			$isObj ? $objectOrClass : null,
			$depth,
			$weak
		);
		if ( $chain ) {
			$out = $base . ' → ' . $chain;
		}
	}

	// Weak chains (heuristic fallbacks that may resolve later, once a lazy
	// framework parses its handler) must not be cached.
	if ( $out !== $base && ! $weak ) {
		$cache[ $key ] = $out;
	}
	return $out;
}

/**
 * Render a Closure: definition site (file:line), scope class and, when the body
 * is a simple delegation, the actual method it calls.
 *
 * @param Closure $closure Closure.
 * @param int     $depth   Depth.
 * @return string
 */
function rest_tracer_render_closure( $closure, $depth = 0 ) {
	static $cache = array();

	$key = 'x' . spl_object_id( $closure );
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	try {
		$ref = new ReflectionFunction( $closure );
	} catch ( Exception $e ) { // phpcs:ignore Squiz.Commenting
		return 'Closure';
	}

	$file = $ref->getFileName();
	$base = 'Closure@' . ( $file ? wp_basename( $file ) . ':' . $ref->getStartLine() : '(internal)' );

	$bound = $ref->getClosureThis();
	$scope = $bound ? get_class( $bound ) : (string) $ref->getClosureScopeClass();
	if ( $scope ) {
		$base .= ' in ' . $scope;
	}

	$out = $base;
	if ( $depth < 3 && $file ) {
		$weak = false;
		$chain = rest_tracer_scan_delegate(
			rest_tracer_source_snippet( $file, $ref->getStartLine(), $ref->getEndLine() ),
			$bound,
			$depth,
			$weak
		);
		if ( $chain ) {
			$out = $base . ' → ' . $chain;
		}
	}

	if ( $out !== $base && ! $weak ) {
		$cache[ $key ] = $out;
	}
	return $out;
}

/**
 * Scan (already comment/string-stripped) method or closure source for a delegation
 * target and resolve it to a callable label.
 *
 * Detection order:
 *  1. A property passed as a handler into a call — call_user_func($this->x, ...,
 *     ($this->x)(..., or ->call($this->x, ... (service-container style).
 *  2. Exactly one $this->method( call on the bound object.
 *  3. Exactly one Class::method( call.
 *  4. Exactly one ->method( call (class unknown).
 *
 * @param string   $src         Stripped source.
 * @param object|null $boundObject Object for $this resolution, if any.
 * @param int      $depth       Current depth.
 * @param bool     &$weak       Set true when the chain came only from the weak
 *                              "->method()" fallback and may improve later.
 * @return string Resolved label, or '' when nothing conclusive was found.
 */
function rest_tracer_scan_delegate( $src, $boundObject, $depth, &$weak = false ) {
	if ( ! $src ) {
		return '';
	}

	// 1) Property holding the handler, passed into a call.
	if ( preg_match( '/call_user_func(?:_array)?\s*\(\s*\$this->(\w+)/', $src, $m )
		|| preg_match( '/\(\s*\$this->(\w+)\s*\)\s*\(/', $src, $m )
		|| preg_match( '/->\s*\w+\s*\(\s*\$this->(\w+)\s*[,)]/', $src, $m ) ) {
		if ( is_object( $boundObject ) ) {
			$value = rest_tracer_read_property( $boundObject, $m[1] );
			if ( rest_tracer_is_callableish( $value ) ) {
				return rest_tracer_render_callable( $value, $depth + 1 );
			}
		}
	}

	// 2) A single $this->method( call.
	if ( is_object( $boundObject ) && preg_match_all( '/\$this->(\w+)\s*\(/', $src, $mm ) ) {
		$uniq = array_values( array_unique( $mm[1] ) );
		if ( 1 === count( $uniq ) ) {
			return rest_tracer_render_callable( array( $boundObject, $uniq[0] ), $depth + 1 );
		}
	}

	// 3) A single Class::method( call.
	if ( preg_match_all( '/([A-Za-z_][\w\\\\]*)::\s*([A-Za-z_]\w*)\s*\(/', $src, $cm ) ) {
		$targets = array();
		foreach ( $cm[1] as $i => $cls ) {
			if ( in_array( strtolower( $cls ), array( 'self', 'static', 'parent' ), true ) ) {
				continue;
			}
			$targets[] = ltrim( $cls, '\\' ) . '::' . $cm[2][ $i ];
		}
		$targets = array_values( array_unique( $targets ) );
		if ( 1 === count( $targets ) ) {
			return $targets[0];
		}
	}

	// 4) A single ->method( call on some variable (class unknown). This is a
	// weak hint: it may resolve to a real class later in the request.
	if ( preg_match_all( '/->\s*(\w+)\s*\(/', $src, $om ) ) {
		$uniq = array_values( array_unique( $om[1] ) );
		if ( 1 === count( $uniq ) ) {
			$weak = true;
			return $uniq[0] . '()';
		}
	}

	return '';
}

/**
 * Whether a value can plausibly be invoked as a handler (Closure, callable,
 * or "Class@method" string that a container would resolve).
 *
 * @param mixed $value Value.
 * @return bool
 */
function rest_tracer_is_callableish( $value ) {
	if ( $value instanceof Closure || is_callable( $value ) ) {
		return true;
	}
	if ( is_string( $value ) && preg_match( '/^[A-Za-z_][\w\\\\]*@(\w+)$/', $value ) ) {
		return true;
	}
	return false;
}

/**
 * Read a (possibly protected/private) property from an object via reflection.
 *
 * @param object $object Object.
 * @param string $prop   Property name.
 * @return mixed|null Null when the property does not exist or is not readable.
 */
function rest_tracer_read_property( $object, $prop ) {
	if ( ! is_object( $object ) || ! is_string( $prop ) || '' === $prop ) {
		return null;
	}
	try {
		$ref = new ReflectionObject( $object );
		if ( ! $ref->hasProperty( $prop ) ) {
			return null;
		}
		$p = $ref->getProperty( $prop );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		return $p->getValue( $object );
	} catch ( Exception $e ) { // phpcs:ignore Squiz.Commenting
		return null;
	}
}

/**
 * Fetch a method's source snippet (comments and strings stripped).
 *
 * @param object|string $class  Class or object.
 * @param string        $method Method name.
 * @return string
 */
function rest_tracer_method_source( $class, $method ) {
	static $cache = array();

	$key = ( is_object( $class ) ? get_class( $class ) : $class ) . '::' . $method;
	if ( array_key_exists( $key, $cache ) ) {
		return $cache[ $key ];
	}

	$src = '';
	try {
		$ref = new ReflectionMethod( $class, $method );
		$src = rest_tracer_source_snippet( $ref->getFileName(), $ref->getStartLine(), $ref->getEndLine() );
	} catch ( Exception $e ) { // phpcs:ignore Squiz.Commenting
		$src = '';
	}

	// Method source is immutable per process; always safe to cache.
	$cache[ $key ] = $src;
	return $src;
}

/**
 * Return a source range with comments and string literals replaced by blanks,
 * so delegate scanning only sees code structure.
 *
 * @param string $file  File path.
 * @param int    $start 1-based start line.
 * @param int    $end   1-based end line.
 * @return string
 */
function rest_tracer_source_snippet( $file, $start, $end ) {
	if ( ! $file || ! is_string( $file ) || ! is_readable( $file ) || $start < 1 ) {
		return '';
	}

	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	if ( ! is_array( $lines ) || count( $lines ) < $end ) {
		return '';
	}

	$end = min( (int) $end, (int) $start + 60 );
	$src = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );
	if ( strlen( $src ) > 8000 ) {
		$src = substr( $src, 0, 8000 );
	}

	$src = preg_replace( '#/\*.*?\*/#s', ' ', $src );
	$src = preg_replace( '#//[^\n]*#', ' ', $src );
	$src = preg_replace( '/#[^\n]*/', ' ', $src );
	$src = preg_replace( '/\'(?:\\\\.|[^\'])*\'/', "''", $src );
	$src = preg_replace( '/"(?:\\\\.|[^"])*"/', '""', $src );

	return (string) $src;
}

/**
 * Normalize a namespace string: trim whitespace and slashes.
 *
 * @param mixed $namespace Raw namespace.
 * @return string
 */
function rest_tracer_normalize_namespace( $namespace ) {
	$ns = trim( (string) $namespace );
	$ns = trim( $ns, '/' );
	$ns = preg_replace( '/\s+/', '', $ns );
	return (string) $ns;
}

/**
 * Whether a REST route ("/ns/resource") belongs to a namespace.
 *
 * @param string $route     Route like "/myshop/v1/orders".
 * @param string $namespace Namespace like "myshop/v1".
 * @return bool
 */
function rest_tracer_namespace_matches( $route, $namespace ) {
	$namespace = rest_tracer_normalize_namespace( $namespace );
	if ( '' === $namespace || '' === (string) $route ) {
		return false;
	}
	$prefix = '/' . $namespace;
	return ( rtrim( (string) $route, '/' ) === $prefix || 0 === strpos( (string) $route, $prefix . '/' ) );
}

/**
 * Parse the comma/space separated namespaces setting into a clean list.
 *
 * @return string[]
 */
function rest_tracer_watched_namespaces() {
	$settings = REST_Tracer_Plugin::get_settings();
	$parts    = preg_split( '/[\s,]+/', (string) $settings['namespaces'] );
	$out      = array();
	foreach ( (array) $parts as $ns ) {
		$ns = rest_tracer_normalize_namespace( $ns );
		if ( '' !== $ns ) {
			$out[] = $ns;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Extract HTTP verbs from a route handler "methods" array
 * (core stores verbs plus capability names like READ/CREATE/EDIT/DELETE).
 *
 * @param array $methods Handler methods array, e.g. ['GET' => true, 'READ' => true].
 * @return string[] Uppercase HTTP verbs.
 */
function rest_tracer_http_verbs( $methods ) {
	$verbs = array_intersect(
		array_keys( array_filter( (array) $methods ) ),
		array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'PURGE' )
	);
	return array_values( $verbs );
}

/**
 * Recursively sanitize request params for storage: mask sensitive keys, truncate strings.
 *
 * @param mixed $value Value.
 * @param int   $depth Current recursion depth.
 * @return mixed
 */
function rest_tracer_sanitize_params( $value, $depth = 0 ) {
	if ( $depth > 4 ) {
		return '…';
	}
	if ( is_array( $value ) ) {
		$out = array();
		$i   = 0;
		foreach ( $value as $k => $v ) {
			if ( $i++ >= 60 ) {
				$out['…'] = 'truncated';
				break;
			}
			$key = is_scalar( $k ) ? (string) $k : gettype( $k );
			if ( preg_match( '/pass|token|secret|auth|key|cookie|nonce/i', $key ) ) {
				$out[ $key ] = '••••••';
				continue;
			}
			$out[ $key ] = rest_tracer_sanitize_params( $v, $depth + 1 );
		}
		return $out;
	}
	if ( is_string( $value ) ) {
		return substr( $value, 0, 500 );
	}
	if ( is_scalar( $value ) || null === $value ) {
		return $value;
	}
	return gettype( $value );
}

/**
 * Build a GraphViz DOT diagram from a decoded trace payload.
 *
 * @param array $payload Payload with ['nodes'] = [ [parent, label, type, start, dur, meta], ... ].
 * @return string
 */
function rest_tracer_build_dot( $payload ) {
	$colors = array(
		'request'    => '#2f6fb3',
		'handler'    => '#00a32a',
		'permission' => '#0d9488',
		'hook'       => '#3858e9',
		'query'      => '#dba617',
		'http'       => '#8e44ad',
		'other'      => '#787c82',
	);

	$lines = array(
		'digraph rest_trace {',
		'  graph [rankdir=LR splines=true fontname="Helvetica"];',
		'  node [shape=box style="rounded,filled" fontname="Helvetica" fontsize=10 fontcolor="white"];',
	);

	$nodes = isset( $payload['nodes'] ) && is_array( $payload['nodes'] ) ? $payload['nodes'] : array();
	foreach ( $nodes as $i => $n ) {
		$label = isset( $n[1] ) ? (string) $n[1] : '';
		$type  = isset( $n[2] ) ? (string) $n[2] : 'other';
		$dur   = isset( $n[4] ) ? (float) $n[4] : 0.0;
		$color = isset( $colors[ $type ] ) ? $colors[ $type ] : $colors['other'];
		$label = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $label );
		$lines[] = sprintf(
			'  n%d [label="%s\n%.2f ms" fillcolor="%s"];',
			$i,
			$label,
			$dur,
			$color
		);
	}
	foreach ( $nodes as $i => $n ) {
		$parent = isset( $n[0] ) ? $n[0] : null;
		if ( null !== $parent && $parent >= 0 && isset( $nodes[ $parent ] ) ) {
			$lines[] = sprintf( '  n%d -> n%d;', (int) $parent, $i );
		}
	}

	$lines[] = '}';
	return implode( "\n", $lines );
}
