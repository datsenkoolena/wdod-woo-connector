<?php
/**
 * Uninstall routine: removes options and queued jobs.
 *
 * Order meta (_wdod_*) is preserved by default because it is part of the order history.
 * Define WDOD_WOO_CONNECTOR_REMOVE_ALL as true in wp-config.php before deleting the plugin
 * to remove the order meta as well.
 *
 * @package WDOD\WooConnector
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wdod_woo_connector_options = array(
	'wdod_woo_connector_enabled',
	'wdod_woo_connector_endpoint',
	'wdod_woo_connector_api_key',
	'wdod_woo_connector_statuses',
	'wdod_woo_connector_max_attempts',
);

foreach ( $wdod_woo_connector_options as $wdod_woo_connector_option ) {
	delete_option( $wdod_woo_connector_option );
}

// Pending background jobs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wdod_woo_connector_sync_order' );
}

if ( function_exists( 'wp_unschedule_hook' ) ) {
	wp_unschedule_hook( 'wdod_woo_connector_sync_order' );
} else {
	wp_clear_scheduled_hook( 'wdod_woo_connector_sync_order' );
}

// Order meta: opt-in only.
if ( defined( 'WDOD_WOO_CONNECTOR_REMOVE_ALL' ) && true === WDOD_WOO_CONNECTOR_REMOVE_ALL ) {
	global $wpdb;

	$wdod_woo_connector_like = $wpdb->esc_like( '_wdod_' ) . '%';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wdod_woo_connector_like ) );

	$wdod_woo_connector_orders_meta = $wpdb->prefix . 'wc_orders_meta';
	$wdod_woo_connector_has_table   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wdod_woo_connector_orders_meta ) );

	if ( $wdod_woo_connector_has_table === $wdod_woo_connector_orders_meta ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wdod_woo_connector_orders_meta} WHERE meta_key LIKE %s", $wdod_woo_connector_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
	}
	// phpcs:enable
}
