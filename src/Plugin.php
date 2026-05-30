<?php
/**
 * Plugin bootstrap.
 *
 * Single entry point. Constructs every service and registers its WordPress
 * hooks. Nothing here does catalog work, it only assembles the services. Mirrors
 * Mage bootstrapping the Magento module.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search;

use Mibizum\Search\Admin\Badges_Editor;
use Mibizum\Search\Admin\Reindex_Page;
use Mibizum\Search\Admin\Rest_Controller;
use Mibizum\Search\Admin\Settings_Tab;
use Mibizum\Search\Admin\Wizard;
use Mibizum\Search\Badges\Badge_Repository;
use Mibizum\Search\Frontend\Widget_Injector;
use Mibizum\Search\Http\Index_Client;
use Mibizum\Search\Http\Search_Client;
use Mibizum\Search\Indexer\Lifecycle_Observer;
use Mibizum\Search\Indexer\Product_Mapper;
use Mibizum\Search\Indexer\Queue;
use Mibizum\Search\Indexer\Scheduler;
use Mibizum\Search\Indexer\Worker;
use Mibizum\Search\Scope\Scope_Resolver;
use Mibizum\Search\Search\Search_Override;
use Mibizum\Search\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	private function __construct() {}

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Assemble services and register hooks. Runs once on plugins_loaded after
	 * WooCommerce is confirmed active.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->load_textdomain();

		// Shared services.
		$this->logger   = new Logger();
		$this->settings = new Settings();
		$settings       = $this->settings;
		$logger         = $this->logger;

		$scopes        = new Scope_Resolver( $settings );
		$index_client  = new Index_Client( $settings, $logger );
		$search_client = new Search_Client( $settings, $logger );
		$badges        = new Badge_Repository( $settings );
		$mapper        = new Product_Mapper( $badges );
		$queue         = new Queue();
		$worker        = new Worker( $queue, $mapper, $index_client, $scopes, $settings, $logger );
		$scheduler     = new Scheduler( $worker, $queue, $index_client, $scopes, $settings, $logger );

		// Indexing: lifecycle hooks + background scheduling.
		( new Lifecycle_Observer( $queue, $scheduler, $settings, $logger ) )->register();
		$scheduler->register();

		// Storefront: search override (with native fallback) + widget injection.
		( new Search_Override( $search_client, $settings, $logger ) )->register();
		( new Widget_Injector( $settings ) )->register();

		// Admin REST is needed on the front end REST namespace too.
		( new Rest_Controller( $settings, $scheduler, $queue ) )->register();

		if ( is_admin() ) {
			$reindex_page = new Reindex_Page( $settings, $queue, $scopes );
			( new Settings_Tab( $settings, $scopes, $scheduler, $reindex_page ) )->register();
			( new Wizard( $settings ) )->register();
			( new Badges_Editor( $settings, $scheduler ) )->register();
		}
	}

	/**
	 * @return void
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'mibizum-search',
			false,
			dirname( MIBIZUM_SEARCH_BASENAME ) . '/languages'
		);
	}

	/**
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}
}
