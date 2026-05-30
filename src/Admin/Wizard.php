<?php
/**
 * First run install wizard.
 *
 * Ports the Magento install wizard to a WordPress native presentation: a
 * dedicated wp-admin screen reached by a one time activation redirect, plus a
 * dismissible "finish setup" admin notice to resume.
 *
 * Hybrid architecture, same as Magento:
 *   - The account / sign in / key provisioning / Widget Studio steps load a
 *     hosted onboarding iframe: {api_url}/onboarding/woocommerce. The iframe
 *     hands the issued keys back via window.postMessage (strict origin check).
 *     The parent posts them to the REST controller, stored encrypted.
 *   - The indexing and widget steps are native (reindex + stats REST endpoints
 *     and the widget snippet field).
 *   - If the SaaS / iframe is unreachable, the wizard offers a "configure
 *     manually" link and never blocks the admin.
 *
 * Dependency to flag: the hosted /onboarding/woocommerce page and its iframe
 * embedding (frame-ancestors) are backend work, like the Magento one. The plugin
 * degrades to manual config until that exists.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Admin;

use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Wizard {

	const PAGE = 'mibizum-search-wizard';

	const STATE_PENDING      = 'pending';
	const STATE_INTRO        = 'intro';
	const STATE_ACCOUNT      = 'account';
	const STATE_PROVISIONING = 'provisioning';
	const STATE_INDEXING     = 'indexing';
	const STATE_WIDGET       = 'widget';
	const STATE_STUDIO       = 'studio';
	const STATE_DONE         = 'done';
	const STATE_DISMISSED    = 'dismissed';

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return string[]
	 */
	public static function allowed_states() {
		return array(
			self::STATE_PENDING,
			self::STATE_INTRO,
			self::STATE_ACCOUNT,
			self::STATE_PROVISIONING,
			self::STATE_INDEXING,
			self::STATE_WIDGET,
			self::STATE_STUDIO,
			self::STATE_DONE,
			self::STATE_DISMISSED,
		);
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( $this, 'resume_notice' ) );
	}

	/**
	 * Hidden admin page (reached via redirect or the notice link).
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			'',
			__( 'Mibizum setup', 'mibizum-search' ),
			__( 'Mibizum setup', 'mibizum-search' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Redirect once after activation if not yet connected.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! get_transient( 'mibizum_search_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'mibizum_search_activation_redirect' );

		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}
		if ( $this->settings->is_enabled_anywhere() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Resume / finish setup notice while not connected and not dismissed/done.
	 *
	 * @return void
	 */
	public function resume_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && self::PAGE === $screen->id ) {
			return; // do not show on the wizard itself.
		}
		if ( $this->settings->is_enabled_anywhere() ) {
			return;
		}
		$state = $this->settings->get_wizard_state();
		if ( self::STATE_DONE === $state ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Finish connecting your store to Mibizum search.', 'mibizum-search' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Set up Mibizum', 'mibizum-search' )
		);
	}

	/**
	 * Hosted onboarding URL for the iframe.
	 *
	 * @return string
	 */
	public function onboarding_url() {
		$base   = rtrim( $this->settings->get_api_url(), '/' ) . '/onboarding/woocommerce';
		$params = array(
			'origin' => home_url(),
			'domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
			'lang'   => substr( (string) get_locale(), 0, 2 ),
		);
		return $base . '?' . http_build_query( $params );
	}

	/**
	 * The origin the parent JS accepts postMessage from (the API URL origin).
	 *
	 * @return string
	 */
	public function mibizum_origin() {
		$url   = $this->settings->get_api_url();
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		return $scheme . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Render the wizard screen.
	 *
	 * @return void
	 */
	public function render() {
		$config = array(
			'restBase'      => esc_url_raw( rest_url( Rest_Controller::NS ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'state'         => $this->settings->get_wizard_state(),
			'onboardingUrl' => $this->onboarding_url(),
			'mibizumOrigin' => $this->mibizum_origin(),
			'settingsUrl'   => admin_url( 'admin.php?page=wc-settings&tab=' . Settings_Tab::TAB ),
			'domain'        => wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		// Template + assets live in templates/wizard.php and assets/wizard.js,
		// kept out of the class so the markup stays reviewable.
		$config_json = wp_json_encode( $config );
		include MIBIZUM_SEARCH_DIR . 'templates/wizard.php';
	}
}
