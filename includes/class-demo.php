<?php
/**
 * Demo endpoint under rest-tracer-demo/v1 — deliberately does realistic work
 * (hook layers, SQL queries, transients, an optional HTTP call and sleeps) so
 * users can verify tracing end-to-end with one click from the admin UI.
 *
 * @package REST_Tracer
 */

defined( 'ABSPATH' ) || exit;

final class REST_Tracer_Demo {

	/**
	 * Register the demo route + demo hooks.
	 */
	public static function register_routes() {
		register_rest_route(
			'rest-tracer-demo/v1',
			'/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'render_report' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'with_http' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Demo hook layers — these show up as nested nodes while tracing.
		add_filter( 'rest_tracer_demo_stage_collect', array( __CLASS__, 'stage_collect' ), 10, 2 );
		add_action( 'rest_tracer_demo_collect', array( __CLASS__, 'on_collect' ) );
		add_filter( 'rest_tracer_demo_stage_transform', array( __CLASS__, 'stage_transform' ), 10, 2 );
		add_filter( 'rest_tracer_demo_item_title', array( __CLASS__, 'uppercase_title' ) );
	}

	/**
	 * Endpoint callback: runs three stages separated by sleeps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function render_report( $request ) {
		$items = apply_filters( 'rest_tracer_demo_stage_collect', array(), $request );

		usleep( 20000 );

		$items = apply_filters( 'rest_tracer_demo_stage_transform', $items, $request );

		if ( $request->get_param( 'with_http' ) ) {
			self::fetch_remote();
		}

		usleep( 15000 );

		return rest_ensure_response(
			array(
				'ok'    => true,
				'items' => count( (array) $items ),
				'hint'  => 'Open Tools → REST Tracer → Traces to inspect this request.',
			)
		);
	}

	/**
	 * Stage 1: collect — queries + transient work inside nested hooks.
	 *
	 * @param array           $items   Items.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function stage_collect( $items, $request ) {
		unset( $request );

		do_action( 'rest_tracer_demo_collect' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' ORDER BY post_date DESC LIMIT 5"
		);

		usleep( 12000 );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Nested demo listener: count query + transient get/set.
	 */
	public static function on_collect() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		get_transient( 'rest_tracer_demo_marker' );
		set_transient( 'rest_tracer_demo_marker', time(), 60 );

		usleep( 6000 );
	}

	/**
	 * Stage 2: transform — per-item filter chain.
	 *
	 * @param array           $items   Items.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function stage_transform( $items, $request ) {
		unset( $request );
		$out = array();
		foreach ( (array) $items as $item ) {
			$title = isset( $item->post_title ) ? $item->post_title : '(unknown)';
			$out[] = array(
				'id'    => isset( $item->ID ) ? (int) $item->ID : 0,
				'title' => apply_filters( 'rest_tracer_demo_item_title', $title ),
			);
		}
		usleep( 8000 );
		return $out;
	}

	/**
	 * Nested demo filter: uppercase titles.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function uppercase_title( $title ) {
		usleep( 2000 );
		return strtoupper( (string) $title );
	}

	/**
	 * Optional outbound HTTP call.
	 *
	 * @return string Status code or error message.
	 */
	private static function fetch_remote() {
		$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 3 ) );
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		return (string) wp_remote_retrieve_response_code( $response );
	}
}
