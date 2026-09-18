<?php
/**
 * Tracer's own REST API (namespace: rest-tracer/v1), used by the admin UI.
 *
 * The traces list in Tools → REST Tracer polls GET /traces?since_id=… so new
 * traces stream into the page without a reload; deletes also go through here.
 *
 * @package REST_Tracer
 */

defined( 'ABSPATH' ) || exit;

final class REST_Tracer_Rest_Api {

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			'rest-tracer/v1',
			'/routes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_routes' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'namespace' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'rest_tracer_normalize_namespace',
					),
				),
			)
		);

		register_rest_route(
			'rest-tracer/v1',
			'/traces',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_traces' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 25,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'since_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			'rest-tracer/v1',
			'/traces/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_trace' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'rest-tracer/v1',
			'/traces/all',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'clear_traces' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			'rest-tracer/v1',
			'/preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'read_preferences' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'write_preferences' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'graph' => array(
							'type' => 'string',
							'enum' => array( 'flame', 'flow' ),
						),
						'tab'   => array(
							'type' => 'string',
							'enum' => array( 'graph', 'waterfall', 'tree' ),
						),
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Viewer preferences (per user, stored in user meta)
	 * ------------------------------------------------------------------- */

	/**
	 * Allowed values per preference key.
	 *
	 * @return array[]
	 */
	private static function prefs_schema() {
		return array(
			'graph' => array( 'flame', 'flow' ),
			'tab'   => array( 'graph', 'waterfall', 'tree' ),
		);
	}

	/**
	 * Current user's viewer preferences, merged over defaults.
	 *
	 * @return array
	 */
	public static function get_user_prefs() {
		$defaults = array(
			'graph' => 'flame',
			'tab'   => 'graph',
		);

		$saved = get_user_meta( get_current_user_id(), 'rest_tracer_prefs', true );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		$out = $defaults;
		foreach ( self::prefs_schema() as $key => $allowed ) {
			if ( isset( $saved[ $key ] ) && in_array( $saved[ $key ], $allowed, true ) ) {
				$out[ $key ] = $saved[ $key ];
			}
		}
		return $out;
	}

	/**
	 * GET /preferences — current viewer preferences.
	 *
	 * @return WP_REST_Response
	 */
	public static function read_preferences() {
		return rest_ensure_response( self::get_user_prefs() );
	}

	/**
	 * PUT /preferences — persist whitelisted viewer preferences for the
	 * current user and return the stored result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function write_preferences( $request ) {
		$saved = get_user_meta( get_current_user_id(), 'rest_tracer_prefs', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		foreach ( self::prefs_schema() as $key => $allowed ) {
			$value = $request->get_param( $key );
			if ( in_array( $value, $allowed, true ) ) {
				$saved[ $key ] = $value;
			}
		}

		update_user_meta( get_current_user_id(), 'rest_tracer_prefs', $saved );

		return rest_ensure_response( self::get_user_prefs() );
	}

	/**
	 * Permission: admins only.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * List all endpoints under a namespace.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_routes( $request ) {
		$namespace = $request['namespace'];
		return rest_ensure_response(
			array(
				'namespace' => $namespace,
				'endpoints' => REST_Tracer_Endpoints::discover( $namespace ),
			)
		);
	}

	/**
	 * List trace summaries — newest page first, or only rows newer than since_id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_traces( $request ) {
		$per_page  = (int) $request->get_param( 'per_page' );
		$since_raw = $request->get_param( 'since_id' );

		if ( null !== $since_raw ) {
			// Live poll: only what the client has not seen yet, oldest → newest.
			return rest_ensure_response( REST_Tracer_Storage::list_since( (int) $since_raw, $per_page ) );
		}

		$page = (int) $request->get_param( 'page' );
		list( $rows ) = REST_Tracer_Storage::list_page( $per_page, $page );
		return rest_ensure_response( $rows );
	}

	/**
	 * Delete a single trace.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_trace( $request ) {
		$id = (int) $request['id'];
		REST_Tracer_Storage::delete( $id );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Delete every stored trace.
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_traces() {
		return rest_ensure_response(
			array(
				'deleted' => REST_Tracer_Storage::clear(),
			)
		);
	}
}
