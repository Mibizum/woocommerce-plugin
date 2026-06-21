<?php
/**
 * Plugin Name:       Mibizum Search for WooCommerce
 * Plugin URI:        https://mibizum.io
 * Description:       Privacy first search for WooCommerce. Indexes the catalog in the background, replaces the native product search with the Mibizum engine (with automatic fallback to native search), and adds the instant search widget.
 * Version:           0.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mibizum
 * Author URI:        https://mibizum.io
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mibizum-search
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   10.0
 *
 * Mibizum Search for WooCommerce.
 *
 * Design principle #1: never break the store. Deactivating the plugin, leaving
 * it unconfigured, or any engine error always falls back to the native
 * WordPress / WooCommerce search. Nothing is published until the connection is
 * enabled and the keys are present.
 *
 * Two API keys are used:
 *   - PRIVATE key (write, indexing): server side secret, stored encrypted, never
 *     exposed to the browser.
 *   - PUBLIC key (read, search): used by the server side search override and
 *     embedded by the merchant in the widget snippet. The only key that reaches
 *     the browser.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'MIBIZUM_SEARCH_VERSION' ) ) {
	return;
}

define( 'MIBIZUM_SEARCH_VERSION', '0.1.2' );
define( 'MIBIZUM_SEARCH_FILE', __FILE__ );
define( 'MIBIZUM_SEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIBIZUM_SEARCH_URL', plugin_dir_url( __FILE__ ) );
define( 'MIBIZUM_SEARCH_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal PSR-4 style autoloader for the Mibizum\Search namespace.
 *
 * Mibizum\Search\Indexer\Queue  ->  src/Indexer/Queue.php
 * No Composer requirement at runtime so the .zip stays dependency free.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Mibizum\\Search\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$relative = substr( $class, $len );
		$path     = MIBIZUM_SEARCH_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Declare compatibility with WooCommerce High Performance Order Storage (HPOS).
 * HPOS only changes order storage, not product hooks, so the indexer is
 * unaffected, but the declaration silences the incompatibility warning.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MIBIZUM_SEARCH_FILE,
				true
			);
		}
	}
);

/**
 * Boot the plugin after all plugins are loaded, so we can detect WooCommerce.
 * If WooCommerce is not active we show an admin notice and do nothing else
 * (safe disable: the storefront is never touched).
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'Mibizum Search requires WooCommerce to be installed and active.', 'mibizum-search' );
					echo '</p></div>';
				}
			);
			return;
		}
		\Mibizum\Search\Plugin::instance()->boot();
	}
);

// Activation / deactivation. These run before the autoloader-driven boot, so we
// reference the installer classes directly through the autoloader.
register_activation_hook( __FILE__, array( \Mibizum\Search\Install\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Mibizum\Search\Install\Deactivator::class, 'deactivate' ) );
