<?php
/**
 * Scope resolver: multi store and multi language.
 *
 * Decision (same as the Magento module): one Mibizum data source per scope. A
 * scope is a WordPress Multisite site and/or a WPML / Polylang language, each
 * with its own connection and indexed in its own translated context.
 *
 * A scope is a plain array:
 *   array(
 *     'key'     => string  // stable identifier, also the Settings scope key
 *     'type'    => 'default' | 'site' | 'language'
 *     'label'   => string
 *     'blog_id' => int|null
 *     'lang'    => string|null
 *   )
 *
 * v1 ships a single default scope, fully working. WPML / Polylang / Multisite
 * enumeration are guarded extension points: when present they add more scopes,
 * and the worker already fans out to N destinations, so enabling them later is
 * additive. Each method is defensive (function_exists / is_multisite guards) so
 * it never fatals on a plain install.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Scope;

use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Scope_Resolver {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * All known scopes (connected or not).
	 *
	 * @return array[]
	 */
	public function all_scopes() {
		$languages = $this->language_scopes();
		if ( ! empty( $languages ) ) {
			return $languages;
		}
		return array( $this->default_scope() );
	}

	/**
	 * The default single scope.
	 *
	 * @return array
	 */
	public function default_scope() {
		return array(
			'key'     => 'default',
			'type'    => 'default',
			'label'   => __( 'Default', 'mibizum-search' ),
			'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null,
			'lang'    => null,
		);
	}

	/**
	 * Language scopes from WPML or Polylang, if active. Empty otherwise.
	 *
	 * @return array[]
	 */
	public function language_scopes() {
		$out = array();

		// WPML.
		if ( has_filter( 'wpml_active_languages' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core hook, consumed not defined.
			$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $langs ) ) {
				foreach ( $langs as $code => $info ) {
					$out[] = array(
						'key'     => 'lang_' . $code,
						'type'    => 'language',
						'label'   => isset( $info['native_name'] ) ? (string) $info['native_name'] : (string) $code,
						'blog_id' => null,
						'lang'    => (string) $code,
					);
				}
				return $out;
			}
		}

		// Polylang.
		if ( function_exists( 'pll_languages_list' ) ) {
			$codes = pll_languages_list();
			if ( is_array( $codes ) ) {
				foreach ( $codes as $code ) {
					$out[] = array(
						'key'     => 'lang_' . $code,
						'type'    => 'language',
						'label'   => (string) $code,
						'blog_id' => null,
						'lang'    => (string) $code,
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Scopes the plugin is connected in (enabled + indexer key + API URL).
	 *
	 * @return array[]
	 */
	public function connected_scopes() {
		$out = array();
		foreach ( $this->all_scopes() as $scope ) {
			if ( $this->settings->is_enabled( $scope['key'] ) ) {
				$out[] = $scope;
			}
		}
		return $out;
	}

	/**
	 * Distinct fan out destinations, deduped by destination signature. Each
	 * destination keeps a representative scope and the languages/sites routing to
	 * it. Mirrors Worker::_resolveDestinations.
	 *
	 * @return array<string,array{scope:array}>
	 */
	public function destinations() {
		$destinations = array();
		foreach ( $this->connected_scopes() as $scope ) {
			$sig = $this->destination_signature( $scope );
			if ( ! isset( $destinations[ $sig ] ) ) {
				$destinations[ $sig ] = array( 'scope' => $scope );
			}
		}
		return $destinations;
	}

	/**
	 * Run a callback inside a scope's context, restoring afterwards (always).
	 *
	 * @param array    $scope
	 * @param callable $callback
	 * @return mixed
	 */
	public function with_scope( array $scope, callable $callback ) {
		$switched_blog = false;
		if ( 'site' === $scope['type'] && $scope['blog_id'] && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( (int) $scope['blog_id'] );
			$switched_blog = true;
		}
		if ( 'language' === $scope['type'] && $scope['lang'] && has_action( 'wpml_switch_language' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core hook, consumed not defined.
			do_action( 'wpml_switch_language', $scope['lang'] );
		}

		try {
			return $callback();
		} finally {
			if ( $switched_blog ) {
				restore_current_blog();
			}
			if ( 'language' === $scope['type'] && has_action( 'wpml_switch_language' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core hook, consumed not defined.
				do_action( 'wpml_switch_language', null );
			}
		}
	}

	/**
	 * Translated product id for a base product in a target scope, or the same id
	 * when no translation layer is active.
	 *
	 * @param int   $product_id
	 * @param array $scope
	 * @return int|null
	 */
	public function translated_product_id( $product_id, array $scope ) {
		if ( 'language' !== $scope['type'] || empty( $scope['lang'] ) ) {
			return (int) $product_id;
		}
		if ( has_filter( 'wpml_object_id' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML core hook, consumed not defined.
			return (int) apply_filters( 'wpml_object_id', $product_id, 'product', false, $scope['lang'] );
		}
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $product_id, $scope['lang'] );
			return $translated ? (int) $translated : null;
		}
		return (int) $product_id;
	}

	/**
	 * Stable signature of a scope's destination (api_url + indexer_key + slug).
	 *
	 * @param array $scope
	 * @return string
	 */
	public function destination_signature( array $scope ) {
		return sha1(
			$this->settings->get_api_url( $scope['key'] ) . '|'
			. $this->settings->get_indexer_key( $scope['key'] ) . '|'
			. (string) $this->settings->get_data_source_slug( $scope['key'] )
		);
	}

	/**
	 * Fingerprint of the whole connection topology across scopes. Compared on
	 * settings save to trigger a reindex only when the topology changed.
	 *
	 * @return string
	 */
	public function connection_fingerprint() {
		$parts = array();
		foreach ( $this->all_scopes() as $scope ) {
			$parts[ $scope['key'] ] = $this->settings->is_enabled( $scope['key'] )
				? $this->destination_signature( $scope )
				: 'off';
		}
		ksort( $parts );
		return sha1( wp_json_encode( $parts ) );
	}
}
