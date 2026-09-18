<?php
/**
 * Endpoint discovery: list every REST route under a namespace.
 *
 * @package REST_Tracer
 */

defined( 'ABSPATH' ) || exit;

final class REST_Tracer_Endpoints {

	/**
	 * Discover all endpoints registered under a namespace.
	 *
	 * @param string $namespace Namespace such as "wc/v3".
	 * @return array[] Each: route, methods, callback, permission, required_args.
	 */
	public static function discover( $namespace ) {
		$server = rest_get_server();
		$routes = $server->get_routes();
		$out    = array();

		foreach ( $routes as $route => $handlers ) {
			if ( ! rest_tracer_namespace_matches( $route, $namespace ) ) {
				continue;
			}
			foreach ( (array) $handlers as $handler ) {
				if ( empty( $handler['callback'] ) ) {
					continue;
				}

				$entry = array(
					'route'      => $route,
					'methods'    => rest_tracer_http_verbs( isset( $handler['methods'] ) ? $handler['methods'] : array() ),
					'callback'   => rest_tracer_render_callable( $handler['callback'] ),
					'permission' => ! empty( $handler['permission_callback'] )
						? rest_tracer_render_callable( $handler['permission_callback'] )
						: '(none)',
				);

				if ( ! empty( $handler['args'] ) && is_array( $handler['args'] ) ) {
					$required = array();
					foreach ( $handler['args'] as $arg_name => $schema ) {
						if ( is_array( $schema ) && ! empty( $schema['required'] ) ) {
							$required[] = $arg_name;
						}
					}
					if ( $required ) {
						$entry['required_args'] = $required;
					}
				}

				$out[] = $entry;
			}
		}

		return $out;
	}
}
