<?php
/**
 * Background scheduling via Action Scheduler (bundled with WooCommerce).
 *
 * Ports the Magento cron jobs (Mibizum_Sync_Model_Scheduler) to Action
 * Scheduler. The request is never blocked: the observer enqueues into Queue and
 * Action Scheduler drains it out of band.
 *
 * Actions (group 'mibizum-search'):
 *   mibizum_search_drain_queue      recurring every 300s.
 *   mibizum_search_drain_queue_now  async one off kicked by the observer
 *                                   (debounced via as_has_scheduled_action).
 *   mibizum_search_full_reindex     recurring daily at ~03:00.
 *   mibizum_search_apply_settings   recurring daily (defensive schema re apply).
 *
 * Safe disable: every callback returns immediately when no scope is connected.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Indexer;

use Mibizum\Search\Http\Index_Client;
use Mibizum\Search\Schema;
use Mibizum\Search\Scope\Scope_Resolver;
use Mibizum\Search\Settings;
use Mibizum\Search\Support\Logger;

defined( 'ABSPATH' ) || exit;

class Scheduler {

	const GROUP          = 'mibizum-search';
	const HOOK_DRAIN     = 'mibizum_search_drain_queue';
	const HOOK_DRAIN_NOW = 'mibizum_search_drain_queue_now';
	const HOOK_FULL      = 'mibizum_search_full_reindex';
	const HOOK_APPLY     = 'mibizum_search_apply_settings';
	const DRAIN_INTERVAL = 300;

	/** @var Worker */
	private $worker;

	/** @var Queue */
	private $queue;

	/** @var Index_Client */
	private $client;

	/** @var Scope_Resolver */
	private $scopes;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct(
		Worker $worker,
		Queue $queue,
		Index_Client $client,
		Scope_Resolver $scopes,
		Settings $settings,
		Logger $logger
	) {
		$this->worker   = $worker;
		$this->queue    = $queue;
		$this->client   = $client;
		$this->scopes   = $scopes;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK_DRAIN, array( $this, 'drain' ) );
		add_action( self::HOOK_DRAIN_NOW, array( $this, 'drain' ) );
		add_action( self::HOOK_FULL, array( $this, 'full_reindex' ) );
		add_action( self::HOOK_APPLY, array( $this, 'apply_engine_settings' ) );
		add_action( 'init', array( $this, 'ensure_schedules' ), 20 );
	}

	/**
	 * Create the recurring actions if missing (idempotent).
	 *
	 * @return void
	 */
	public function ensure_schedules() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK_DRAIN, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::DRAIN_INTERVAL, self::DRAIN_INTERVAL, self::HOOK_DRAIN, array(), self::GROUP );
		}
		if ( ! as_has_scheduled_action( self::HOOK_FULL, array(), self::GROUP ) ) {
			$first = strtotime( 'tomorrow 3:00' );
			as_schedule_recurring_action( $first ?: time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_FULL, array(), self::GROUP );
		}
		if ( ! as_has_scheduled_action( self::HOOK_APPLY, array(), self::GROUP ) ) {
			$first = strtotime( 'tomorrow 3:30' );
			as_schedule_recurring_action( $first ?: time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_APPLY, array(), self::GROUP );
		}
	}

	/**
	 * Kick an async drain soon (debounced). Called after enqueue.
	 *
	 * @return void
	 */
	public function kick_async_drain() {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK_DRAIN_NOW, array(), self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_DRAIN_NOW, array(), self::GROUP );
		}
	}

	/**
	 * Drain callback. Safe disable aware.
	 *
	 * @return void
	 */
	public function drain() {
		if ( ! $this->settings->is_enabled_anywhere() ) {
			return;
		}
		try {
			$this->worker->drain( 200 );
		} catch ( \Exception $e ) {
			$this->logger->error( 'drain failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Full reindex: apply settings, enqueue all published products, drain, report.
	 *
	 * @param string $trigger 'cron' | 'manual'.
	 * @return void
	 */
	public function full_reindex( $trigger = 'cron' ) {
		if ( ! $this->settings->is_enabled_anywhere() ) {
			return;
		}

		$started_at = microtime( true );
		$status     = 'success';
		$error      = null;
		$totals     = array(
			'processed' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'deleted'   => 0,
		);

		$this->logger->info( 'full_reindex starting (trigger=' . $trigger . ')' );

		try {
			try {
				$this->apply_engine_settings();
			} catch ( \Exception $e ) {
				$this->logger->warning( 'full_reindex: apply_engine_settings failed (continuing): ' . $e->getMessage() );
			}

			$ids = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);
			$enqueued = $this->queue->enqueue_bulk_upsert( array_map( 'intval', (array) $ids ), 'full_reindex' );
			$this->logger->info( 'full_reindex enqueued ' . $enqueued . ' products' );

			$totals = $this->worker->drain();
			if ( ! empty( $totals['failed'] ) ) {
				$status = 'partial';
			}
		} catch ( \Exception $e ) {
			$status = 'failed';
			$error  = $e->getMessage();
			$this->logger->error( 'full_reindex failed: ' . $error );
		}

		$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		// Best effort telemetry to the first connected scope.
		$connected = $this->scopes->connected_scopes();
		if ( ! empty( $connected ) ) {
			$scope_key = $connected[0]['key'];
			$this->scopes->with_scope(
				$connected[0],
				function () use ( $scope_key, $status, $trigger, $totals, $duration_ms, $error ) {
					$this->client->report_sync_run(
						array(
							'status'        => $status,
							'trigger'       => $trigger,
							'items_updated' => (int) $totals['succeeded'] - (int) $totals['deleted'],
							'items_removed' => (int) $totals['deleted'],
							'items_failed'  => (int) $totals['failed'],
							'duration_ms'   => $duration_ms,
							'error_message' => $error,
						),
						$scope_key
					);
				}
			);
		}

		update_option( 'mibizum_search_last_full_at', gmdate( 'c' ) );
		update_option( 'mibizum_search_last_full_status', $status );
	}

	/**
	 * Re apply the schema to each connected data source.
	 *
	 * @return void
	 */
	public function apply_engine_settings() {
		$schema = Schema::build_search_schema();
		foreach ( $this->scopes->connected_scopes() as $scope ) {
			$this->scopes->with_scope(
				$scope,
				function () use ( $schema, $scope ) {
					try {
						$this->client->apply_settings( $schema, $scope['key'] );
					} catch ( \Exception $e ) {
						$this->logger->warning( 'apply_engine_settings failed for scope ' . $scope['key'] . ': ' . $e->getMessage() );
					}
				}
			);
		}
	}

	/**
	 * Schedule a one off full reindex (used by the reindex panel / wizard).
	 *
	 * @param string $trigger
	 * @return void
	 */
	public function schedule_full_reindex( $trigger = 'manual' ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_FULL, array( $trigger ), self::GROUP );
		} else {
			$this->full_reindex( $trigger );
		}
	}

	/**
	 * Remove all scheduled actions (called on deactivation).
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}
