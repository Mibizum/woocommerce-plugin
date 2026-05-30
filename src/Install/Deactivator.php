<?php
/**
 * Deactivation.
 *
 * Reversible pause: unschedules the Action Scheduler jobs so no background work
 * runs while the plugin is off. Keeps all data (options, tables) so the
 * connection can be resumed by reactivating. Deleting the plugin (uninstall.php)
 * is what wipes data.
 *
 * The store keeps working either way: with the plugin inactive the search
 * override and widget are simply not loaded, and search is native WooCommerce.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Install;

use Mibizum\Search\Indexer\Scheduler;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	/**
	 * @return void
	 */
	public static function deactivate() {
		// TODO: Scheduler::unschedule_all(). Do NOT drop tables or options here.
		if ( class_exists( Scheduler::class ) ) {
			Scheduler::unschedule_all();
		}
	}
}
