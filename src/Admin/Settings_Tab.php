<?php
/**
 * WooCommerce settings tab.
 *
 * Configuration under WooCommerce, Settings, Mibizum, with sub sections that
 * mirror the single Magento "Mibizum Sync" section: Connection, Frontend, Sync,
 * Badges and Reindex.
 *
 * Secret fields (indexer + search keys) are encrypted on save (Support\Crypto)
 * and never echoed back: a blank submit keeps the stored value. On a connection
 * topology change a reindex is scheduled (fingerprint compare).
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Admin;

use Mibizum\Search\Indexer\Scheduler;
use Mibizum\Search\Scope\Scope_Resolver;
use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Settings_Tab {

	const TAB = 'mibizum';

	/** @var Settings */
	private $settings;

	/** @var Scope_Resolver */
	private $scopes;

	/** @var Scheduler */
	private $scheduler;

	/** @var Reindex_Page */
	private $reindex_page;

	public function __construct( Settings $settings, Scope_Resolver $scopes, Scheduler $scheduler, Reindex_Page $reindex_page ) {
		$this->settings     = $settings;
		$this->scopes       = $scopes;
		$this->scheduler    = $scheduler;
		$this->reindex_page = $reindex_page;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_' . self::TAB, array( $this, 'output' ) );
		add_action( 'woocommerce_sections_' . self::TAB, array( $this, 'output_sections' ) );
		add_action( 'woocommerce_settings_save_' . self::TAB, array( $this, 'save' ) );
	}

	/**
	 * @param array $tabs
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Mibizum', 'mibizum-search' );
		return $tabs;
	}

	/**
	 * @return string
	 */
	private function current_section() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['section'] ) ? sanitize_title( wp_unslash( $_GET['section'] ) ) : '';
	}

	/**
	 * @return array
	 */
	private function sections() {
		return array(
			''         => __( 'Connection', 'mibizum-search' ),
			'frontend' => __( 'Frontend', 'mibizum-search' ),
			'sync'     => __( 'Sync', 'mibizum-search' ),
			'badges'   => __( 'Badges', 'mibizum-search' ),
			'reindex'  => __( 'Reindex', 'mibizum-search' ),
		);
	}

	/**
	 * Render the section sub navigation.
	 *
	 * @return void
	 */
	public function output_sections() {
		$current = $this->current_section();
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $this->sections() as $id => $label ) {
			$url     = admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB . ( $id ? '&section=' . $id : '' ) );
			$class   = ( $current === $id ) ? 'current' : '';
			$links[] = '<li><a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo wp_kses_post( implode( ' | ', $links ) );
		echo '</ul><br class="clear" />';
	}

	/**
	 * Render the active section.
	 *
	 * @return void
	 */
	public function output() {
		$section = $this->current_section();
		if ( 'reindex' === $section ) {
			$this->reindex_page->render();
			return;
		}
		\WC_Admin_Settings::output_fields( $this->fields( $section ) );
	}

	/**
	 * @return void
	 */
	public function save() {
		$section     = $this->current_section();
		$fingerprint = $this->scopes->connection_fingerprint();

		switch ( $section ) {
			case 'frontend':
				$this->save_frontend();
				break;
			case 'sync':
				$this->save_sync();
				break;
			case 'badges':
				$this->save_badges();
				break;
			case 'reindex':
				break;
			default:
				$this->save_connection();
				break;
		}

		// Reindex when the connection topology changed.
		$new_fingerprint = $this->scopes->connection_fingerprint();
		if ( $new_fingerprint !== $fingerprint ) {
			update_option( Settings::OPTION_FINGERPRINT, $new_fingerprint );
			if ( $this->settings->is_enabled_anywhere() ) {
				$this->scheduler->schedule_full_reindex( 'connection_changed' );
				\WC_Admin_Settings::add_message( __( 'Connection changed: a full reindex was scheduled.', 'mibizum-search' ) );
			}
		}
	}

	// --- Field definitions ----------------------------------------------

	/**
	 * @param string $section
	 * @return array
	 */
	private function fields( $section ) {
		switch ( $section ) {
			case 'frontend':
				return $this->frontend_fields();
			case 'sync':
				return $this->sync_fields();
			case 'badges':
				return $this->badges_fields();
			default:
				return $this->connection_fields();
		}
	}

	/**
	 * @return array
	 */
	private function connection_fields() {
		$key_note = function ( $is_set ) {
			return $is_set
				? __( 'A key is stored. Leave blank to keep it.', 'mibizum-search' )
				: __( 'Stored encrypted. Never sent to the browser.', 'mibizum-search' );
		};
		return array(
			array(
				'type'  => 'title',
				'id'    => 'mbz_connection_title',
				'title' => __( 'Connection', 'mibizum-search' ),
				'desc'  => __( 'The only step needed for the plugin to start working. Nothing is published until this is enabled and the keys are present.', 'mibizum-search' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => 'mbz_enabled',
				'title'   => __( 'Enabled', 'mibizum-search' ),
				'desc'    => __( 'Turn on indexing and the search override.', 'mibizum-search' ),
				'value'   => $this->settings->get( 'enabled', false ) ? 'yes' : 'no',
			),
			array(
				'type'    => 'text',
				'id'      => 'mbz_api_url',
				'title'   => __( 'API URL', 'mibizum-search' ),
				'value'   => $this->settings->get_api_url(),
			),
			array(
				'type'              => 'password',
				'id'                => 'mbz_indexer_key',
				'title'             => __( 'Private API key', 'mibizum-search' ),
				'desc'              => $key_note( '' !== $this->settings->get_indexer_key() ),
				'value'             => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			array(
				'type'              => 'password',
				'id'                => 'mbz_search_key',
				'title'             => __( 'Public API key', 'mibizum-search' ),
				'desc'              => $key_note( '' !== $this->settings->get_search_key() ),
				'value'             => '',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			array(
				'type'  => 'text',
				'id'    => 'mbz_data_source_slug',
				'title' => __( 'Data source slug', 'mibizum-search' ),
				'desc'  => __( 'The catalog slug. Empty uses the account first data source.', 'mibizum-search' ),
				'value' => (string) $this->settings->get_data_source_slug(),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mbz_connection_end',
			),
		);
	}

	/**
	 * @return array
	 */
	private function frontend_fields() {
		return array(
			array(
				'type'  => 'title',
				'id'    => 'mbz_frontend_title',
				'title' => __( 'Frontend', 'mibizum-search' ),
			),
			array(
				'type'  => 'checkbox',
				'id'    => 'mbz_widget_enabled',
				'title' => __( 'Enable widget', 'mibizum-search' ),
				'value' => $this->settings->is_widget_enabled() ? 'yes' : 'no',
			),
			array(
				'type'  => 'textarea',
				'id'    => 'mbz_widget_snippet',
				'title' => __( 'Widget snippet', 'mibizum-search' ),
				'desc'  => __( 'Paste the snippet from your Mibizum panel (Domains, JS code). Injected verbatim in the head. Empty means no widget.', 'mibizum-search' ),
				'css'   => 'min-width:400px;height:120px;font-family:monospace;',
				'value' => $this->settings->get_widget_snippet(),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mbz_frontend_end',
			),
		);
	}

	/**
	 * @return array
	 */
	private function sync_fields() {
		return array(
			array(
				'type'  => 'title',
				'id'    => 'mbz_sync_title',
				'title' => __( 'Sync', 'mibizum-search' ),
				'desc'  => __( 'Sensible defaults. Rarely need changing.', 'mibizum-search' ),
			),
			array(
				'type'              => 'number',
				'id'                => 'mbz_batch_size',
				'title'             => __( 'Batch size', 'mibizum-search' ),
				'value'             => $this->settings->get_batch_size(),
				'custom_attributes' => array( 'min' => 1, 'max' => 500 ),
			),
			array(
				'type'              => 'number',
				'id'                => 'mbz_max_attempts',
				'title'             => __( 'Maximum retries', 'mibizum-search' ),
				'value'             => $this->settings->get_max_attempts(),
				'custom_attributes' => array( 'min' => 1 ),
			),
			array(
				'type'              => 'number',
				'id'                => 'mbz_timeout_seconds',
				'title'             => __( 'HTTP timeout (s)', 'mibizum-search' ),
				'value'             => $this->settings->get_timeout_seconds(),
				'custom_attributes' => array( 'min' => 1 ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mbz_sync_end',
			),
		);
	}

	/**
	 * @return array
	 */
	private function badges_fields() {
		$cfg = $this->settings->get_system_badges_config();
		return array(
			array(
				'type'  => 'title',
				'id'    => 'mbz_badges_title',
				'title' => __( 'Badges', 'mibizum-search' ),
				'desc'  => __( 'Labels travel inside the indexed document. Changing them schedules a reindex.', 'mibizum-search' )
					. ' <a href="' . esc_url( Badges_Editor::url() ) . '">' . esc_html__( 'Manage category and attribute badges', 'mibizum-search' ) . '</a>.',
			),
			array(
				'type'  => 'checkbox',
				'id'    => 'mbz_badge_system',
				'title' => __( 'System badges', 'mibizum-search' ),
				'value' => $this->settings->show_system_badges() ? 'yes' : 'no',
			),
			array(
				'type'  => 'checkbox',
				'id'    => 'mbz_badge_category',
				'title' => __( 'Category badges', 'mibizum-search' ),
				'value' => $this->settings->show_category_badges() ? 'yes' : 'no',
			),
			array(
				'type'  => 'checkbox',
				'id'    => 'mbz_badge_attribute',
				'title' => __( 'Attribute badges', 'mibizum-search' ),
				'value' => $this->settings->show_attribute_badges() ? 'yes' : 'no',
			),
			array(
				'type'  => 'text',
				'id'    => 'mbz_badge_out_of_stock_label',
				'title' => __( 'Out of stock label', 'mibizum-search' ),
				'value' => $cfg['out_of_stock']['label'],
			),
			array(
				'type'  => 'text',
				'id'    => 'mbz_badge_low_stock_label',
				'title' => __( 'Last units label', 'mibizum-search' ),
				'value' => $cfg['low_stock']['label'],
			),
			array(
				'type'              => 'number',
				'id'                => 'mbz_badge_low_stock_threshold',
				'title'             => __( 'Last units threshold', 'mibizum-search' ),
				'value'             => $cfg['low_stock']['threshold'],
				'custom_attributes' => array( 'min' => 1 ),
			),
			array(
				'type'  => 'text',
				'id'    => 'mbz_badge_in_offer_label',
				'title' => __( 'On sale label', 'mibizum-search' ),
				'value' => $cfg['in_offer']['label'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'mbz_badges_end',
			),
		);
	}

	// --- Save handlers ---------------------------------------------------

	/**
	 * @return void
	 */
	private function save_connection() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->settings->update(
			array(
				'enabled'          => isset( $_POST['mbz_enabled'] ) && 'yes' === $_POST['mbz_enabled'],
				'api_url'          => isset( $_POST['mbz_api_url'] ) ? esc_url_raw( wp_unslash( $_POST['mbz_api_url'] ) ) : '',
				'data_source_slug' => isset( $_POST['mbz_data_source_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['mbz_data_source_slug'] ) ) : '',
			)
		);
		$indexer = isset( $_POST['mbz_indexer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mbz_indexer_key'] ) ) : '';
		if ( '' !== $indexer ) {
			$this->settings->set_indexer_key( $indexer );
		}
		$search = isset( $_POST['mbz_search_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mbz_search_key'] ) ) : '';
		if ( '' !== $search ) {
			$this->settings->set_search_key( $search );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * @return void
	 */
	private function save_frontend() {
		// Nonce is verified by WC_Admin_Settings::save() before this fires.
		// The widget snippet is a trusted, admin-only script loader stored
		// verbatim (it cannot be run through sanitize_text_field without breaking
		// the <script> tag), like core's custom head scripts.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$snippet = isset( $_POST['mbz_widget_snippet'] ) ? trim( (string) wp_unslash( $_POST['mbz_widget_snippet'] ) ) : '';
		$this->settings->update(
			array(
				'widget_enabled' => isset( $_POST['mbz_widget_enabled'] ) && 'yes' === $_POST['mbz_widget_enabled'],
				'widget_snippet' => $snippet,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * @return void
	 */
	private function save_sync() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->settings->update(
			array(
				'batch_size'      => isset( $_POST['mbz_batch_size'] ) ? (int) $_POST['mbz_batch_size'] : 100,
				'max_attempts'    => isset( $_POST['mbz_max_attempts'] ) ? (int) $_POST['mbz_max_attempts'] : 10,
				'timeout_seconds' => isset( $_POST['mbz_timeout_seconds'] ) ? (int) $_POST['mbz_timeout_seconds'] : 15,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * @return void
	 */
	private function save_badges() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$this->settings->update(
			array(
				'badge_system'                 => isset( $_POST['mbz_badge_system'] ) && 'yes' === $_POST['mbz_badge_system'],
				'badge_category'               => isset( $_POST['mbz_badge_category'] ) && 'yes' === $_POST['mbz_badge_category'],
				'badge_attribute'              => isset( $_POST['mbz_badge_attribute'] ) && 'yes' === $_POST['mbz_badge_attribute'],
				'badge_out_of_stock_label'     => isset( $_POST['mbz_badge_out_of_stock_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mbz_badge_out_of_stock_label'] ) ) : '',
				'badge_low_stock_label'        => isset( $_POST['mbz_badge_low_stock_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mbz_badge_low_stock_label'] ) ) : '',
				'badge_low_stock_threshold'    => isset( $_POST['mbz_badge_low_stock_threshold'] ) ? (int) $_POST['mbz_badge_low_stock_threshold'] : 5,
				'badge_in_offer_label'         => isset( $_POST['mbz_badge_in_offer_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mbz_badge_in_offer_label'] ) ) : '',
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
