<?php
/**
 * Uninstall routine for Mibizum Search.
 *
 * Runs only when the merchant deletes the plugin from wp-admin. Removes every
 * trace: options, transients, the queue and badge tables, and any scheduled
 * Action Scheduler jobs. On multisite it cleans every site.
 *
 * Pausing (deactivating) keeps the data so the connection can be resumed.
 * Deleting wipes it. Either way the store keeps working: search falls back to
 * native WordPress / WooCommerce search.
 *
 * This file runs WITHOUT the plugin bootstrap (no autoloader), so names are
 * referenced literally rather than via class constants.
 *
 * @package Mibizum\Search
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove all plugin data for the current site.
 *
 * @return void
 */
function mibizum_search_uninstall_site() {
	global $wpdb;

	// 1. Options (including per-scope settings copies: mibizum_search_settings_*).
	$options = array(
		'mibizum_search_settings',
		'mibizum_search_wizard_state',
		'mibizum_search_connection_fingerprint',
		'mibizum_search_last_full_at',
		'mibizum_search_last_full_status',
		'mibizum_search_db_version',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	// Per-scope option arrays and runtime-config transients (LIKE cleanup).
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mibizum_search_settings\\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mibizum_search\\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_mibizum_search\\_%'" );

	// 2. Custom tables.
	$tables = array(
		$wpdb->prefix . 'mibizum_search_index_queue',
		$wpdb->prefix . 'mibizum_search_category_badge_terms',
		$wpdb->prefix . 'mibizum_search_category_badges',
		$wpdb->prefix . 'mibizum_search_attribute_badges',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	// 3. Scheduled Action Scheduler jobs (group mibizum-search).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'mibizum-search' );
	}
}

if ( is_multisite() ) {
	$mibizum_search_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $mibizum_search_site_ids as $mibizum_search_site_id ) {
		switch_to_blog( (int) $mibizum_search_site_id );
		mibizum_search_uninstall_site();
		restore_current_blog();
	}
} else {
	mibizum_search_uninstall_site();
}
