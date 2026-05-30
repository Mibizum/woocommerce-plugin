<?php
/**
 * Storefront widget injection.
 *
 * Ports Mibizum_Sync_Block_Frontend_Config. Echoes the widget snippet the
 * merchant pasted from the Mibizum panel (Domains, JS code) verbatim into
 * wp_head. The plugin does not build the script tag: the panel emits an
 * evergreen loader or a pinned version, and we only print what was pasted.
 *
 * Gated by the widget master switch + a non empty snippet (per current scope).
 * Empty snippet, or widget disabled, injects nothing. Never runs in admin,
 * feeds or REST.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Frontend;

use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Widget_Injector {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'inject' ), 20 );
	}

	/**
	 * @return void
	 */
	public function inject() {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$scope = $this->current_scope_key();
		if ( ! $this->settings->is_widget_enabled( $scope ) ) {
			return;
		}
		$snippet = $this->settings->get_widget_snippet( $scope );
		if ( '' === $snippet ) {
			return;
		}

		// The snippet is trusted admin input (the merchant pasted it themselves),
		// printed verbatim like core's custom head scripts. It is not escaped on
		// purpose: it is a <script> loader.
		echo "\n<!-- Mibizum Search widget -->\n";
		echo $snippet; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n";
	}

	/**
	 * @return string|null
	 */
	private function current_scope_key() {
		if ( function_exists( 'pll_current_language' ) ) {
			$code = pll_current_language( 'slug' );
			if ( $code ) {
				return 'lang_' . $code;
			}
		}
		if ( has_filter( 'wpml_current_language' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core hook, consumed not defined.
			$code = apply_filters( 'wpml_current_language', null );
			if ( $code ) {
				return 'lang_' . $code;
			}
		}
		return null;
	}
}
