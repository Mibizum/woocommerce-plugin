<?php
/**
 * Category and attribute badge editor (admin CRUD).
 *
 * Ports the Magento Natures / Attribute badges admin grids. Manages the badge
 * definitions in the custom tables (created by the Activator); the resolution
 * and indexing path already live in Badges\Badge_Repository.
 *
 * Lives as a hidden submenu under WooCommerce, reached from the Badges section
 * of the Mibizum settings tab. Form posts go through admin-post.php with a nonce
 * and the manage_woocommerce capability. After any change, if connected, a
 * reindex is scheduled so the new labels propagate into the documents.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Admin;

use Mibizum\Search\Badges\Badge_Repository;
use Mibizum\Search\Indexer\Scheduler;
use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Badges_Editor {

	const PAGE = 'mibizum-search-badges';

	/** @var Settings */
	private $settings;

	/** @var Scheduler */
	private $scheduler;

	public function __construct( Settings $settings, Scheduler $scheduler ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_mibizum_save_category_badge', array( $this, 'save_category_badge' ) );
		add_action( 'admin_post_mibizum_delete_category_badge', array( $this, 'delete_category_badge' ) );
		add_action( 'admin_post_mibizum_save_attribute_badge', array( $this, 'save_attribute_badge' ) );
		add_action( 'admin_post_mibizum_delete_attribute_badge', array( $this, 'delete_attribute_badge' ) );
	}

	/**
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'',
			__( 'Mibizum badges', 'mibizum-search' ),
			__( 'Mibizum badges', 'mibizum-search' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'mibizum-search' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['type'] ) && 'attribute' === $_GET['type'] ? 'attribute' : 'category';
		echo '<div class="wrap"><h1>' . esc_html__( 'Mibizum badges', 'mibizum-search' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( self::url() . '&type=category' ),
			'category' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Category badges', 'mibizum-search' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( self::url() . '&type=attribute' ),
			'attribute' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Attribute badges', 'mibizum-search' )
		);
		echo '</h2>';

		if ( 'attribute' === $tab ) {
			$this->render_attribute_tab();
		} else {
			$this->render_category_tab();
		}
		echo '</div>';
	}

	// --- Category badges -------------------------------------------------

	private function render_category_tab() {
		global $wpdb;
		$defs  = Badge_Repository::category_table();
		$terms = Badge_Repository::category_terms_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a trusted internal constant ($wpdb->prefix + literal), not user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$defs} ORDER BY sort_priority ASC, id ASC", ARRAY_A );

		echo '<table class="widefat striped" style="max-width:760px;margin:14px 0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Color', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Categories', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Priority', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'mibizum-search' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		if ( $rows ) {
			foreach ( $rows as $r ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a trusted internal constant ($wpdb->prefix + literal), not user input.
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$terms} WHERE badge_id = %d", $r['id'] ) );
				echo '<tr>';
				echo '<td>' . esc_html( $r['label'] ) . '</td>';
				echo '<td><span style="display:inline-block;width:16px;height:16px;border:1px solid #ccc;background:' . esc_attr( $r['color_hex'] ) . '"></span> ' . esc_html( $r['color_hex'] ) . '</td>';
				echo '<td>' . esc_html( $count ) . '</td>';
				echo '<td>' . esc_html( $r['sort_priority'] ) . '</td>';
				echo '<td>' . ( $r['enabled'] ? esc_html__( 'Yes', 'mibizum-search' ) : esc_html__( 'No', 'mibizum-search' ) ) . '</td>';
				echo '<td>' . $this->delete_button( 'mibizum_delete_category_badge', (int) $r['id'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="6">' . esc_html__( 'No category badges yet.', 'mibizum-search' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Add form.
		echo '<h3>' . esc_html__( 'Add a category badge', 'mibizum-search' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mibizum_category_badge' );
		echo '<input type="hidden" name="action" value="mibizum_save_category_badge" />';
		echo '<table class="form-table" role="presentation">';
		$this->text_row( 'label', __( 'Label', 'mibizum-search' ) );
		$this->color_row();
		$this->number_row( 'sort_priority', __( 'Priority (lower wins)', 'mibizum-search' ), 0 );
		echo '<tr><th>' . esc_html__( 'Categories', 'mibizum-search' ) . '</th><td>';
		$this->category_multiselect();
		echo '<p><label><input type="checkbox" name="include_children" value="1" checked /> ' . esc_html__( 'Include subcategories', 'mibizum-search' ) . '</label></p>';
		echo '</td></tr>';
		$this->enabled_row();
		echo '</table>';
		submit_button( __( 'Add category badge', 'mibizum-search' ) );
		echo '</form>';
	}

	/**
	 * @return void
	 */
	public function save_category_badge() {
		$this->guard( 'mibizum_category_badge' );
		global $wpdb;
		$defs  = Badge_Repository::category_table();
		$terms = Badge_Repository::category_terms_table();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		if ( '' === $label ) {
			$this->redirect_back( 'category' );
		}
		$wpdb->insert(
			$defs,
			array(
				'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
				'label'         => $label,
				'color_hex'     => $this->clean_color( isset( $_POST['color_hex'] ) ? sanitize_text_field( wp_unslash( $_POST['color_hex'] ) ) : '' ),
				'sort_priority' => isset( $_POST['sort_priority'] ) ? (int) $_POST['sort_priority'] : 0,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$badge_id = (int) $wpdb->insert_id;

		$include  = isset( $_POST['include_children'] ) ? 1 : 0;
		$cat_ids  = isset( $_POST['categories'] ) ? array_map( 'intval', (array) $_POST['categories'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		foreach ( $cat_ids as $term_id ) {
			if ( $term_id > 0 ) {
				$wpdb->insert( $terms, array( 'badge_id' => $badge_id, 'term_id' => $term_id, 'include_children' => $include ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$this->after_change();
		$this->redirect_back( 'category' );
	}

	/**
	 * @return void
	 */
	public function delete_category_badge() {
		$this->guard( 'mibizum_delete_category_badge' );
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id > 0 ) {
			$wpdb->delete( Badge_Repository::category_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Badge_Repository::category_terms_table(), array( 'badge_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->after_change();
		}
		$this->redirect_back( 'category' );
	}

	// --- Attribute badges ------------------------------------------------

	private function render_attribute_tab() {
		global $wpdb;
		$defs = Badge_Repository::attribute_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a trusted internal constant ($wpdb->prefix + literal), not user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$defs} ORDER BY sort_priority ASC, id ASC", ARRAY_A );

		echo '<table class="widefat striped" style="max-width:760px;margin:14px 0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Attribute', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Value (term slug)', 'mibizum-search' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'mibizum-search' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		if ( $rows ) {
			foreach ( $rows as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( $r['label'] ) . '</td>';
				echo '<td>' . esc_html( $r['taxonomy'] ) . '</td>';
				echo '<td>' . esc_html( '' !== $r['match_value'] ? $r['match_value'] : __( '(any)', 'mibizum-search' ) ) . '</td>';
				echo '<td>' . ( $r['enabled'] ? esc_html__( 'Yes', 'mibizum-search' ) : esc_html__( 'No', 'mibizum-search' ) ) . '</td>';
				echo '<td>' . $this->delete_button( 'mibizum_delete_attribute_badge', (int) $r['id'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="5">' . esc_html__( 'No attribute badges yet.', 'mibizum-search' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Add an attribute badge', 'mibizum-search' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mibizum_attribute_badge' );
		echo '<input type="hidden" name="action" value="mibizum_save_attribute_badge" />';
		echo '<table class="form-table" role="presentation">';
		$this->text_row( 'label', __( 'Label', 'mibizum-search' ) );
		echo '<tr><th>' . esc_html__( 'Attribute', 'mibizum-search' ) . '</th><td>';
		$this->attribute_taxonomy_select();
		echo '</td></tr>';
		$this->text_row( 'match_value', __( 'Value (term slug, empty = any)', 'mibizum-search' ) );
		$this->color_row();
		$this->number_row( 'sort_priority', __( 'Priority (lower wins)', 'mibizum-search' ), 0 );
		$this->enabled_row();
		echo '</table>';
		submit_button( __( 'Add attribute badge', 'mibizum-search' ) );
		echo '</form>';
	}

	/**
	 * @return void
	 */
	public function save_attribute_badge() {
		$this->guard( 'mibizum_attribute_badge' );
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		if ( '' === $label || '' === $taxonomy ) {
			$this->redirect_back( 'attribute' );
		}
		$wpdb->insert(
			Badge_Repository::attribute_table(),
			array(
				'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
				'taxonomy'      => $taxonomy,
				'match_value'   => isset( $_POST['match_value'] ) ? sanitize_title( wp_unslash( $_POST['match_value'] ) ) : '',
				'label'         => $label,
				'color_hex'     => $this->clean_color( isset( $_POST['color_hex'] ) ? sanitize_text_field( wp_unslash( $_POST['color_hex'] ) ) : '' ),
				'sort_priority' => isset( $_POST['sort_priority'] ) ? (int) $_POST['sort_priority'] : 0,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->after_change();
		$this->redirect_back( 'attribute' );
	}

	/**
	 * @return void
	 */
	public function delete_attribute_badge() {
		$this->guard( 'mibizum_delete_attribute_badge' );
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id > 0 ) {
			$wpdb->delete( Badge_Repository::attribute_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->after_change();
		}
		$this->redirect_back( 'attribute' );
	}

	// --- Shared form helpers --------------------------------------------

	private function text_row( $name, $label, $value = '' ) {
		echo '<tr><th><label for="mbz_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" class="regular-text" id="mbz_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" /></td></tr>';
	}

	private function number_row( $name, $label, $value = 0 ) {
		echo '<tr><th>' . esc_html( $label ) . '</th>';
		echo '<td><input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" /></td></tr>';
	}

	private function color_row() {
		echo '<tr><th>' . esc_html__( 'Color', 'mibizum-search' ) . '</th>';
		echo '<td><input type="text" name="color_hex" value="#2271b1" placeholder="#2271b1" /></td></tr>';
	}

	private function enabled_row() {
		echo '<tr><th>' . esc_html__( 'Enabled', 'mibizum-search' ) . '</th>';
		echo '<td><label><input type="checkbox" name="enabled" value="1" checked /> ' . esc_html__( 'Active', 'mibizum-search' ) . '</label></td></tr>';
	}

	private function category_multiselect() {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		echo '<select name="categories[]" multiple size="8" style="min-width:280px;">';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select>';
	}

	private function attribute_taxonomy_select() {
		echo '<select name="taxonomy">';
		echo '<option value="">' . esc_html__( 'Select an attribute', 'mibizum-search' ) . '</option>';
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $tax ) {
				$name = wc_attribute_taxonomy_name( $tax->attribute_name );
				echo '<option value="' . esc_attr( $name ) . '">' . esc_html( $tax->attribute_label . ' (' . $name . ')' ) . '</option>';
			}
		}
		echo '</select>';
	}

	private function delete_button( $action, $id ) {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Delete this badge?', 'mibizum-search' ) ) . '\');">';
		$html .= wp_nonce_field( $action, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		$html .= '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		$html .= '<button type="submit" class="button-link-delete button-link">' . esc_html__( 'Delete', 'mibizum-search' ) . '</button>';
		$html .= '</form>';
		return $html;
	}

	private function clean_color( $value ) {
		$value = (string) $value;
		return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ? $value : '#2271b1';
	}

	private function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'mibizum-search' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function after_change() {
		if ( $this->settings->is_enabled_anywhere() ) {
			$this->scheduler->schedule_full_reindex( 'badge_changed' );
		}
	}

	private function redirect_back( $type ) {
		wp_safe_redirect( self::url() . '&type=' . $type );
		exit;
	}
}
