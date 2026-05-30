<?php
/**
 * Admin REST endpoints.
 *
 * Backs the wizard and the reindex panel. Ports the Magento WizardController +
 * ReindexController actions to the WordPress REST API. Every route requires the
 * manage_woocommerce capability. The indexer key is a secret: it is encrypted
 * before persisting and never returned or logged.
 *
 * Namespace: mibizum-search/v1
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Admin;

use Mibizum\Search\Indexer\Queue;
use Mibizum\Search\Indexer\Scheduler;
use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	const NS = 'mibizum-search/v1';

	/** @var Settings */
	private $settings;

	/** @var Scheduler */
	private $scheduler;

	/** @var Queue */
	private $queue;

	public function __construct( Settings $settings, Scheduler $scheduler, Queue $queue ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->queue     = $queue;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * @return void
	 */
	public function routes() {
		$auth = array( $this, 'can_manage' );

		register_rest_route(
			self::NS,
			'/reindex/full',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reindex_full' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/reindex/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'reindex_stats' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/wizard/save-keys',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_keys' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/wizard/widget',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_widget' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/wizard/state',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_state' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/wizard/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => $auth,
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function reindex_full( $request ) {
		if ( ! $this->settings->is_enabled_anywhere() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Not connected: set the connection (enabled + indexer key + API URL) before reindexing.', 'mibizum-search' ),
				),
				400
			);
		}
		$this->scheduler->schedule_full_reindex( 'manual' );
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function reindex_stats( $request ) {
		return new \WP_REST_Response(
			array(
				'queue'           => $this->queue->stats(),
				'index'           => array( 'numberOfDocuments' => null ),
				'last_full_at'    => get_option( 'mibizum_search_last_full_at', null ),
				'last_full_status' => get_option( 'mibizum_search_last_full_status', null ),
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function save_keys( $request ) {
		$indexer = trim( (string) $request->get_param( 'indexer_key' ) );
		$search  = trim( (string) $request->get_param( 'search_key' ) );
		$slug    = trim( (string) $request->get_param( 'data_source_slug' ) );

		if ( '' === $indexer && '' === $search ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No keys received from Mibizum.', 'mibizum-search' ),
				),
				400
			);
		}

		if ( '' !== $indexer ) {
			$this->settings->set_indexer_key( $indexer );
		}
		if ( '' !== $search ) {
			$this->settings->set_search_key( $search );
		}
		$this->settings->update(
			array(
				'data_source_slug' => sanitize_text_field( $slug ),
				'enabled'          => true,
			)
		);
		$this->settings->set_wizard_state( Wizard::STATE_INDEXING );
		$this->scheduler->schedule_full_reindex( 'wizard' );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'state'   => Wizard::STATE_INDEXING,
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function save_widget( $request ) {
		$snippet = (string) $request->get_param( 'widget_snippet' );
		$this->settings->update(
			array(
				'widget_snippet' => $this->sanitize_snippet( $snippet ),
				'widget_enabled' => true,
			)
		);
		$this->settings->set_wizard_state( Wizard::STATE_STUDIO );
		return new \WP_REST_Response(
			array(
				'success' => true,
				'state'   => Wizard::STATE_STUDIO,
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function set_state( $request ) {
		$state = trim( (string) $request->get_param( 'state' ) );
		if ( ! in_array( $state, Wizard::allowed_states(), true ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Unknown wizard state.', 'mibizum-search' ),
				),
				400
			);
		}
		$this->settings->set_wizard_state( $state );
		return new \WP_REST_Response(
			array(
				'success' => true,
				'state'   => $state,
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function dismiss( $request ) {
		$this->settings->set_wizard_state( Wizard::STATE_DISMISSED );
		return new \WP_REST_Response(
			array(
				'success' => true,
				'state'   => Wizard::STATE_DISMISSED,
			),
			200
		);
	}

	/**
	 * Allow the script loader markup of the snippet but strip dangerous handlers
	 * is not appropriate (it is a script). The merchant pasted it; we keep it as
	 * is but require the manage_woocommerce capability to set it.
	 *
	 * @param string $snippet
	 * @return string
	 */
	private function sanitize_snippet( $snippet ) {
		return trim( (string) $snippet );
	}
}
