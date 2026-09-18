<?php
/**
 * Uninstall cleanup: drop the traces table and remove options.
 *
 * @package REST_Tracer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$table = $wpdb->prefix . 'rest_tracer_traces';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'rest_tracer_settings' );
delete_option( 'rest_tracer_version' );
