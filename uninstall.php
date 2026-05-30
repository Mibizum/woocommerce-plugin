<?php
/**
 * Uninstall routine for Mibizum Search.
 *
 * Runs only when the merchant deletes the plugin from wp-admin. Removes every
 * trace: options, the queue and badge tables, and any scheduled Action
 * Scheduler jobs. On multisite it cleans every site.
 *
 * Pausing (deactivating) keeps the data so the connection can be resumed.
 * Deleting wipes it. Either way the store keeps working: search falls back to
 * native WordPress / WooCommerce search.
 *
 * TODO: implement the actual cleanup once the option keys and table names are
 * finalized in Settings, Queue and Badge_Repository. Keep it idempotent and
 * defensive (never fatal on a partial install).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// TODO: delete options (mibizum_search_*), drop custom tables
//       ({$wpdb->prefix}mibizum_search_index_queue and the badge tables),
//       and unschedule the Action Scheduler group 'mibizum-search'.
// TODO: on multisite, loop get_sites() with switch_to_blog()/restore_current_blog().
