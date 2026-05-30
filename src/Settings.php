<?php
/**
 * Configuration access.
 *
 * Centralizes every option read/write. Mirrors Mibizum_Sync_Helper_Data: it
 * owns the "is the module connected" logic, the two API keys (encrypted), the
 * data source slug, the widget snippet, the badge toggles, the sync tuning, and
 * the wizard state.
 *
 * Scope aware: a "scope" is a WordPress Multisite site and/or a WPML / Polylang
 * language, each mapping to one Mibizum data source. Config is stored in a per
 * scope option array that falls back to the default scope for unset keys. With
 * no multilingual plugin and no multisite there is a single default scope.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search;

use Mibizum\Search\Support\Crypto;
use Mibizum\Search\Scope\Scope_Resolver;

defined( 'ABSPATH' ) || exit;

class Settings {

	/** Option array for the default scope. */
	const OPTION = 'mibizum_search_settings';

	/** Wizard state option (see Admin\Wizard). */
	const OPTION_WIZARD_STATE = 'mibizum_search_wizard_state';

	/** Stored connection topology fingerprint (reindex on change). */
	const OPTION_FINGERPRINT = 'mibizum_search_connection_fingerprint';

	/** Default backend base URL. */
	const DEFAULT_API_URL = 'https://app.mibizum.io';

	/**
	 * Resolve the option name for a scope. The default scope uses OPTION; other
	 * scopes use OPTION . '_' . sanitized key.
	 *
	 * @param string|null $scope
	 * @return string
	 */
	private function option_name( $scope = null ) {
		if ( null === $scope || '' === $scope || 'default' === $scope ) {
			return self::OPTION;
		}
		return self::OPTION . '_' . preg_replace( '/[^a-z0-9_]/i', '_', (string) $scope );
	}

	/**
	 * The full settings array for a scope (scope values merged over defaults).
	 *
	 * @param string|null $scope
	 * @return array
	 */
	public function all( $scope = null ) {
		$data = get_option( $this->option_name( $scope ), array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( null !== $scope && '' !== $scope && 'default' !== $scope ) {
			$base = get_option( self::OPTION, array() );
			if ( is_array( $base ) ) {
				$data = array_merge( $base, $data );
			}
		}
		return $data;
	}

	/**
	 * @param string      $key
	 * @param mixed       $default
	 * @param string|null $scope
	 * @return mixed
	 */
	public function get( $key, $default = null, $scope = null ) {
		$data = $this->all( $scope );
		return array_key_exists( $key, $data ) ? $data[ $key ] : $default;
	}

	/**
	 * Merge values into a scope's option array.
	 *
	 * @param array       $values
	 * @param string|null $scope
	 * @return void
	 */
	public function update( array $values, $scope = null ) {
		$name = $this->option_name( $scope );
		$data = get_option( $name, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		update_option( $name, array_merge( $data, $values ) );
	}

	// --- Connection ------------------------------------------------------

	/**
	 * Connection on AND minimally configured (indexer key + API URL).
	 *
	 * @param string|null $scope
	 * @return bool
	 */
	public function is_enabled( $scope = null ) {
		return (bool) $this->get( 'enabled', false, $scope )
			&& '' !== $this->get_indexer_key( $scope )
			&& '' !== $this->get_api_url( $scope );
	}

	/**
	 * Server side search usable: connection on plus SEARCH key plus API URL.
	 * Does NOT require the indexer key.
	 *
	 * @param string|null $scope
	 * @return bool
	 */
	public function is_search_enabled( $scope = null ) {
		return (bool) $this->get( 'enabled', false, $scope )
			&& '' !== $this->get_search_key( $scope )
			&& '' !== $this->get_api_url( $scope );
	}

	/**
	 * Connected in the current scope OR any other scope.
	 *
	 * @return bool
	 */
	public function is_enabled_anywhere() {
		if ( $this->is_enabled() ) {
			return true;
		}
		$resolver = new Scope_Resolver( $this );
		foreach ( $resolver->connected_scopes() as $ignored ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string|null $scope
	 * @return string Trailing slash trimmed; defaults to DEFAULT_API_URL.
	 */
	public function get_api_url( $scope = null ) {
		$url = rtrim( trim( (string) $this->get( 'api_url', '', $scope ) ), '/' );
		return '' !== $url ? $url : self::DEFAULT_API_URL;
	}

	/**
	 * Indexer key (write scope). Decrypted on read. Never exposed to the browser.
	 *
	 * @param string|null $scope
	 * @return string
	 */
	public function get_indexer_key( $scope = null ) {
		return Crypto::decrypt( (string) $this->get( 'indexer_key_enc', '', $scope ) );
	}

	/**
	 * Search key (read scope).
	 *
	 * @param string|null $scope
	 * @return string
	 */
	public function get_search_key( $scope = null ) {
		return Crypto::decrypt( (string) $this->get( 'search_key_enc', '', $scope ) );
	}

	/**
	 * Store the indexer key encrypted. Empty input clears it.
	 *
	 * @param string      $plain
	 * @param string|null $scope
	 * @return void
	 */
	public function set_indexer_key( $plain, $scope = null ) {
		$this->update( array( 'indexer_key_enc' => Crypto::encrypt( (string) $plain ) ), $scope );
	}

	/**
	 * @param string      $plain
	 * @param string|null $scope
	 * @return void
	 */
	public function set_search_key( $plain, $scope = null ) {
		$this->update( array( 'search_key_enc' => Crypto::encrypt( (string) $plain ) ), $scope );
	}

	/**
	 * @param string|null $scope
	 * @return string|null
	 */
	public function get_data_source_slug( $scope = null ) {
		$slug = trim( (string) $this->get( 'data_source_slug', '', $scope ) );
		return '' !== $slug ? $slug : null;
	}

	/**
	 * Widget JS snippet pasted from the Mibizum panel. Injected verbatim.
	 *
	 * @param string|null $scope
	 * @return string
	 */
	public function get_widget_snippet( $scope = null ) {
		return trim( (string) $this->get( 'widget_snippet', '', $scope ) );
	}

	/**
	 * Storefront widget master switch (independent from the connection switch).
	 *
	 * @param string|null $scope
	 * @return bool
	 */
	public function is_widget_enabled( $scope = null ) {
		return (bool) $this->get( 'widget_enabled', false, $scope );
	}

	// --- Sync tuning -----------------------------------------------------

	/** @return int 1..500, default 100. */
	public function get_batch_size() {
		$n = (int) $this->get( 'batch_size', 100 );
		if ( $n < 1 ) {
			$n = 100;
		}
		return min( $n, 500 );
	}

	/** @return int default 10. */
	public function get_max_attempts() {
		$n = (int) $this->get( 'max_attempts', 10 );
		return $n < 1 ? 10 : $n;
	}

	/** @return int seconds, default 15. */
	public function get_timeout_seconds() {
		$n = (int) $this->get( 'timeout_seconds', 15 );
		return $n < 1 ? 15 : $n;
	}

	// --- Badge master toggles -------------------------------------------

	/** @return bool */
	public function show_system_badges() {
		return (bool) $this->get( 'badge_system', true );
	}

	/** @return bool */
	public function show_category_badges() {
		return (bool) $this->get( 'badge_category', true );
	}

	/** @return bool */
	public function show_attribute_badges() {
		return (bool) $this->get( 'badge_attribute', true );
	}

	/**
	 * System badge configuration. Labels fall back to translated defaults when
	 * empty. Mirrors Helper::getBadgesConfig.
	 *
	 * @return array
	 */
	public function get_system_badges_config() {
		$on = $this->show_system_badges();

		$enabled = function ( $key, $default ) use ( $on ) {
			return $on ? (bool) $this->get( 'badge_' . $key . '_enabled', $default ) : false;
		};
		$label = function ( $key, $default ) {
			$value = trim( (string) $this->get( 'badge_' . $key . '_label', '' ) );
			return '' !== $value ? $value : $default;
		};

		return array(
			'out_of_stock' => array(
				'enabled' => $enabled( 'out_of_stock', true ),
				'label'   => $label( 'out_of_stock', __( 'Out of stock', 'mibizum-search' ) ),
			),
			'in_offer'     => array(
				'enabled' => $enabled( 'in_offer', true ),
				'label'   => $label( 'in_offer', __( 'On sale', 'mibizum-search' ) ),
			),
			'low_stock'    => array(
				'enabled'   => $enabled( 'low_stock', true ),
				'label'     => $label( 'low_stock', __( 'Last units', 'mibizum-search' ) ),
				'threshold' => (int) $this->get( 'badge_low_stock_threshold', 5 ),
			),
			'new'          => array(
				'enabled' => $enabled( 'new', false ),
				'label'   => $label( 'new', __( 'New', 'mibizum-search' ) ),
				'days'    => (int) $this->get( 'badge_new_days', 30 ),
			),
			'featured'     => array(
				'enabled' => $enabled( 'featured', false ),
				'label'   => $label( 'featured', __( 'Featured', 'mibizum-search' ) ),
			),
		);
	}

	// --- Wizard ----------------------------------------------------------

	/** @return string One of Admin\Wizard::STATE_*; 'pending' when unset. */
	public function get_wizard_state() {
		$state = trim( (string) get_option( self::OPTION_WIZARD_STATE, '' ) );
		return '' !== $state ? $state : 'pending';
	}

	/**
	 * @param string $state
	 * @return void
	 */
	public function set_wizard_state( $state ) {
		update_option( self::OPTION_WIZARD_STATE, (string) $state );
	}
}
