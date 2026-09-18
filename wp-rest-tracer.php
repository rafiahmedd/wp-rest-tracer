<?php
/**
 * Plugin Name:       REST API Tracer
 * Description:       Records REST API endpoints by namespace, traces every hook callback, SQL query and HTTP request between request and response with nested timings, and renders interactive graph views — a flame graph and a node-map flow diagram — with a live, no-reload trace list.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            rafiahmedd
 * Author URI:        https://github.com/rafiahmedd
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       rest-tracer
 */

defined( 'ABSPATH' ) || exit;

define( 'REST_TRACER_VERSION', '1.0.0' );
define( 'REST_TRACER_FILE', __FILE__ );
define( 'REST_TRACER_DIR', plugin_dir_path( __FILE__ ) );
define( 'REST_TRACER_URL', plugin_dir_url( __FILE__ ) );

require_once REST_TRACER_DIR . 'includes/functions.php';
require_once REST_TRACER_DIR . 'includes/class-storage.php';
require_once REST_TRACER_DIR . 'includes/class-endpoints.php';
require_once REST_TRACER_DIR . 'includes/class-tracer.php';
require_once REST_TRACER_DIR . 'includes/class-rest-api.php';
require_once REST_TRACER_DIR . 'includes/class-demo.php';
require_once REST_TRACER_DIR . 'includes/class-admin.php';

/**
 * Plugin orchestrator: settings, wiring and upgrades.
 */
final class REST_Tracer_Plugin {

	private static $instance        = null;
	private static $settings_cache  = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function default_settings() {
		return array(
			'namespaces'    => '',
			'mode'          => 'header',
			'token'         => wp_generate_password( 32, false, false ),
			'capture_hooks' => 1,
			'capture_sql'   => 1,
			'capture_http'  => 1,
			'max_nodes'     => 10000,
			'max_traces'    => 50,
		);
	}

	public static function get_settings() {
		if ( null === self::$settings_cache ) {
			self::$settings_cache = wp_parse_args(
				(array) get_option( 'rest_tracer_settings', array() ),
				self::default_settings()
			);
		}
		return self::$settings_cache;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_upgrade' ) );

		// Arms itself on rest_pre_dispatch; zero cost for non-matching requests.
		REST_Tracer_Tracer::instance();

		if ( is_admin() ) {
			REST_Tracer_Admin::instance();
		}

		add_action( 'rest_api_init', array( 'REST_Tracer_Rest_Api', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'REST_Tracer_Demo', 'register_routes' ) );
	}

	public function maybe_upgrade() {
		if ( get_option( 'rest_tracer_version' ) !== REST_TRACER_VERSION ) {
			REST_Tracer_Storage::install();
			update_option( 'rest_tracer_version', REST_TRACER_VERSION );
		}
	}
}

register_activation_hook( __FILE__, array( 'REST_Tracer_Storage', 'install' ) );
add_action( 'plugins_loaded', array( 'REST_Tracer_Plugin', 'instance' ) );
