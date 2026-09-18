<?php
/**
 * Trace storage: custom table + payload encode/decode.
 *
 * @package REST_Tracer
 */

defined( 'ABSPATH' ) || exit;

final class REST_Tracer_Storage {

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rest_tracer_traces';
	}

	/**
	 * Create / upgrade the table via dbDelta.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return true;
        }

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			namespace varchar(100) NOT NULL DEFAULT '',
			route varchar(191) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT '',
			status smallint(5) unsigned NOT NULL DEFAULT 0,
			duration_ms decimal(14,3) NOT NULL DEFAULT 0,
			nodes int(10) unsigned NOT NULL DEFAULT 0,
			peak_memory bigint(20) unsigned NOT NULL DEFAULT 0,
			truncated tinyint(1) NOT NULL DEFAULT 0,
			compressed tinyint(1) NOT NULL DEFAULT 0,
			data longtext,
			PRIMARY KEY  (id),
			KEY created (created),
			KEY namespace (namespace)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a finished trace and prune old rows.
	 *
	 * @param array $args { namespace, route, method, status, duration_ms, nodes, peak_memory, truncated, payload }.
	 * @param int   $keep  Max traces to keep.
	 * @return int Inserted trace id.
	 */
	public static function insert_trace( array $args, $keep = 50 ) {
		global $wpdb;

		$enc = self::encode_payload( $args['payload'] );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created'      => current_time( 'mysql', true ),
				'namespace'    => substr( (string) $args['namespace'], 0, 100 ),
				'route'        => substr( (string) $args['route'], 0, 191 ),
				'method'       => substr( (string) $args['method'], 0, 10 ),
				'status'       => (int) $args['status'],
				'duration_ms'  => (float) $args['duration_ms'],
				'nodes'        => (int) $args['nodes'],
				'peak_memory'  => (int) $args['peak_memory'],
				'truncated'    => (int) $args['truncated'],
				'compressed'   => $enc['compressed'],
				'data'         => $enc['data'],
			),
			array( '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		self::prune( (int) $keep );

		return $id;
	}

	/**
	 * Keep only the newest $keep traces.
	 *
	 * @param int $keep Max rows to keep.
	 */
	public static function prune( $keep ) {
		global $wpdb;

		$keep = max( 1, (int) $keep );
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$threshold = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
				$keep
			)
		);

		if ( $threshold > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $threshold ) );
		}
	}

	/**
	 * Fetch a single trace row.
	 *
	 * @param int $id Trace id.
	 * @return object|null
	 */
	public static function get_row( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Delete a trace.
	 *
	 * @param int $id Trace id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Traces newer than a given id — the live-polling query behind the admin list.
	 *
	 * @param int $since_id Last id the client has already seen.
	 * @param int $limit    Max rows to return.
	 * @return array Rows ordered oldest → newest (so the client can prepend in order).
	 */
	public static function list_since( $since_id, $limit = 50 ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id, created, namespace, route, method, status, duration_ms, nodes, peak_memory, truncated
				 FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				(int) $since_id,
				max( 1, min( 100, (int) $limit ) )
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * Delete all traces.
	 *
	 * Uses DELETE (not TRUNCATE) so the auto-increment counter keeps rising:
	 * reused ids after a truncate would be invisible to since_id polling.
	 *
	 * @return int Number of rows removed.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Paginated trace list + total count.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $paged    1-based page number.
	 * @return array [ rows[], total ]
	 */
	public static function list_page( $per_page = 25, $paged = 1 ) {
		global $wpdb;

		$table    = self::table();
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$paged    = max( 1, (int) $paged );
		$offset   = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id, created, namespace, route, method, status, duration_ms, nodes, peak_memory, truncated
				 FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return array( $rows ? $rows : array(), $total );
	}

	/**
	 * Encode a trace payload for storage; gz-compress large payloads.
	 *
	 * @param array $payload Payload array.
	 * @return array [ data => string, compressed => 0|1 ]
	 */
	public static function encode_payload( array $payload ) {
		$json = wp_json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json || null === $json ) {
			$json = wp_json_encode( array( 'meta' => array(), 'nodes' => array() ) );
		}
		if ( strlen( $json ) > 200 * 1024 ) {
			return array(
				'data'       => base64_encode( (string) gzdeflate( $json, 6 ) ),
				'compressed' => 1,
			);
		}
		return array( 'data' => $json, 'compressed' => 0 );
	}

	/**
	 * Decode a stored payload back to an array.
	 *
	 * @param string $data       Stored data column.
	 * @param int    $compressed Compressed flag.
	 * @return array
	 */
	public static function decode_payload( $data, $compressed ) {
		$raw = (int) $compressed ? gzinflate( base64_decode( (string) $data ) ) : (string) $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$payload = json_decode( (string) $raw, true );
		if ( ! is_array( $payload ) ) {
			return array( 'meta' => array(), 'nodes' => array() );
		}
		return $payload;
	}
}
