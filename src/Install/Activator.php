<?php
/**
 * Activation.
 *
 * Creates the custom tables (queue + badge definitions) with dbDelta, and flags
 * the one time wizard redirect. Recurring Action Scheduler jobs are ensured
 * lazily on boot (Scheduler), not here, because Action Scheduler may not be
 * loaded at activation time.
 *
 * Safe by default: nothing is published until the merchant enables the
 * connection and provides the keys.
 *
 * Multisite: on network activation, run per site.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Install;

use Mibizum\Search\Indexer\Queue;
use Mibizum\Search\Badges\Badge_Repository;

defined( 'ABSPATH' ) || exit;

class Activator {

	/**
	 * @param bool $network_wide
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids' ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::create_tables();
				restore_current_blog();
			}
		} else {
			self::create_tables();
			// Trigger the first run wizard (single site activation only).
			set_transient( 'mibizum_search_activation_redirect', 1, 60 );
		}
	}

	/**
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$queue            = Queue::table();
		$category_badges  = Badge_Repository::category_table();
		$category_terms   = Badge_Repository::category_terms_table();
		$attribute_badges = Badge_Repository::attribute_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$queue} (
			queue_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation varchar(16) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			sku varchar(64) DEFAULT NULL,
			reason varchar(64) DEFAULT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			locked_at datetime DEFAULT NULL,
			locked_by varchar(64) DEFAULT NULL,
			last_error text DEFAULT NULL,
			enqueued_at datetime NOT NULL,
			PRIMARY KEY  (queue_id),
			UNIQUE KEY product_operation (product_id,operation),
			KEY locked_at (locked_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$category_badges} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			label varchar(190) NOT NULL DEFAULT '',
			color_hex varchar(9) NOT NULL DEFAULT '',
			text_color_hex varchar(9) DEFAULT NULL,
			position varchar(20) NOT NULL DEFAULT 'top-left',
			shape varchar(20) NOT NULL DEFAULT 'pill',
			sort_priority int(10) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$category_terms} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			badge_id bigint(20) unsigned NOT NULL,
			term_id bigint(20) unsigned NOT NULL,
			include_children tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY badge_id (badge_id),
			KEY term_id (term_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$attribute_badges} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			taxonomy varchar(64) NOT NULL DEFAULT '',
			match_value varchar(190) NOT NULL DEFAULT '',
			label varchar(190) NOT NULL DEFAULT '',
			color_hex varchar(9) NOT NULL DEFAULT '',
			text_color_hex varchar(9) DEFAULT NULL,
			position varchar(20) NOT NULL DEFAULT 'top-left',
			shape varchar(20) NOT NULL DEFAULT 'pill',
			sort_priority int(10) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'mibizum_search_db_version', MIBIZUM_SEARCH_VERSION );
	}
}
