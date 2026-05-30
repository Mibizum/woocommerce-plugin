<?php
/**
 * Catalog lifecycle hooks.
 *
 * Ports Mibizum_Sync_Model_Observer. Keeps the index in sync with catalog
 * changes by enqueuing operations (never blocking the request with an HTTP
 * call). Each handler short circuits when the plugin is not connected anywhere
 * (safe disable), enqueues into Queue, then kicks an async Action Scheduler
 * drain so the change publishes within seconds.
 *
 * Hooks (CRUD first; the WooCommerce CRUD hooks fire once and cover REST / CLI /
 * quick edit, unlike save_post which also fires on autosave/revisions):
 *   woocommerce_new_product, woocommerce_update_product        -> upsert
 *   woocommerce_before_delete_product, wp_trash_post           -> delete (capture SKU)
 *   untrashed_post                                             -> upsert
 *   woocommerce_product_set_stock, woocommerce_variation_set_stock,
 *   woocommerce_product_set_stock_status,
 *   woocommerce_variation_set_stock_status,
 *   woocommerce_updated_product_stock (legacy)                 -> upsert
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Indexer;

use Mibizum\Search\Settings;
use Mibizum\Search\Support\Logger;

defined( 'ABSPATH' ) || exit;

class Lifecycle_Observer {

	/** @var Queue */
	private $queue;

	/** @var Scheduler */
	private $scheduler;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct( Queue $queue, Scheduler $scheduler, Settings $settings, Logger $logger ) {
		$this->queue     = $queue;
		$this->scheduler = $scheduler;
		$this->settings  = $settings;
		$this->logger    = $logger;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_untrashed' ), 10, 1 );

		add_action( 'woocommerce_before_delete_product', array( $this, 'on_product_deleted' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_product_deleted' ), 10, 1 );

		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_object' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_object' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_updated_product_stock', array( $this, 'on_stock_legacy' ), 10, 1 );
	}

	/**
	 * @param int $product_id
	 * @return void
	 */
	public function on_product_saved( $product_id ) {
		$this->enqueue_upsert( (int) $product_id, 'product_save' );
	}

	/**
	 * @param int $post_id
	 * @return void
	 */
	public function on_untrashed( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			$this->enqueue_upsert( (int) $post_id, 'product_untrashed' );
		}
	}

	/**
	 * Capture the SKU now (pre delete): documents are keyed by id derived from
	 * the SKU, and the product is gone by drain time.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function on_product_deleted( $post_id ) {
		if ( ! $this->settings->is_enabled_anywhere() ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		try {
			$product = wc_get_product( $post_id );
			$sku     = $product ? (string) $product->get_sku() : '';
			$this->queue->enqueue_delete( (int) $post_id, $sku, 'product_delete' );
			$this->scheduler->kick_async_drain();
		} catch ( \Exception $e ) {
			$this->logger->error( 'on_product_deleted failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param \WC_Product $product
	 * @return void
	 */
	public function on_stock_object( $product ) {
		if ( $product && is_a( $product, 'WC_Product' ) ) {
			$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$this->enqueue_upsert( (int) $id, 'stock_change' );
		}
	}

	/**
	 * @param int    $product_id
	 * @param string $status
	 * @param mixed  $product
	 * @return void
	 */
	public function on_stock_status( $product_id, $status = '', $product = null ) {
		$id = $product_id;
		if ( $product && is_a( $product, 'WC_Product' ) && $product->is_type( 'variation' ) ) {
			$id = $product->get_parent_id();
		}
		$this->enqueue_upsert( (int) $id, 'stock_status_change' );
	}

	/**
	 * @param int $product_id
	 * @return void
	 */
	public function on_stock_legacy( $product_id ) {
		$this->enqueue_upsert( (int) $product_id, 'stock_change' );
	}

	/**
	 * @param int    $product_id
	 * @param string $reason
	 * @return void
	 */
	private function enqueue_upsert( $product_id, $reason ) {
		if ( $product_id <= 0 || ! $this->settings->is_enabled_anywhere() ) {
			return;
		}
		try {
			$this->queue->enqueue_upsert( $product_id, $reason );
			$this->scheduler->kick_async_drain();
		} catch ( \Exception $e ) {
			$this->logger->error( 'enqueue_upsert failed (' . $reason . '): ' . $e->getMessage() );
		}
	}
}
