<?php
/**
 * Search API client (read scope, SEARCH key).
 *
 * Ports Mibizum_Sync_Model_Search_Adapter. Used by the native search override
 * so the results page uses the Mibizum engine, consistent with the JS widget.
 * Short timeouts: the customer is waiting for the results page render.
 *
 * Endpoints (confirmed):
 *   GET {api_url}/api/v1/search?q&limit&offset&strategy=auto&source=<slug>
 *       Bearer SEARCH key + Origin header -> { hits, totalHits, processingMs, ... }
 *   GET {api_url}/api/v1/runtime-config?source=<slug>
 *       Origin header, no auth -> { settings: { matchingStrategy, ... } }
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Http;

use Mibizum\Search\Settings;
use Mibizum\Search\Support\Logger;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

class Search_Client {

	/** Backend cap per call. */
	const MAX_LIMIT = 50;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	/**
	 * @param Settings $settings
	 * @param Logger   $logger
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Run a search. Throws on 5xx / network failure so the override can fall
	 * back to native search. A real 0 hits is returned as 0 hits (no fallback).
	 *
	 * @param array       $params { q, limit, offset, strategy }
	 * @param string|null $scope
	 * @return array{hits:array,query:string,totalHits:int,processingMs:int,degraded:bool,strategyUsed:string,raw:?array}
	 * @throws RuntimeException
	 */
	public function search( array $params, $scope = null ) {
		$q = isset( $params['q'] ) ? (string) $params['q'] : '';
		if ( '' === $q ) {
			return $this->empty_result();
		}

		$key = $this->settings->get_search_key( $scope );
		$url = $this->settings->get_api_url( $scope );
		if ( '' === $key || '' === $url ) {
			throw new RuntimeException( 'Search client: search key or API URL not configured' );
		}

		$query = array(
			'q'        => $q,
			'limit'    => isset( $params['limit'] ) ? min( (int) $params['limit'], self::MAX_LIMIT ) : self::MAX_LIMIT,
			'offset'   => isset( $params['offset'] ) ? max( (int) $params['offset'], 0 ) : 0,
			'strategy' => isset( $params['strategy'] ) ? (string) $params['strategy'] : 'auto',
		);
		$slug = $this->settings->get_data_source_slug( $scope );
		if ( $slug ) {
			$query['source'] = $slug;
		}

		$full = rtrim( $url, '/' ) . '/api/v1/search?' . http_build_query( $query );

		$response = wp_remote_get(
			$full,
			array(
				'timeout' => $this->settings->get_timeout_seconds(),
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
					'Origin'        => $this->origin(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Search client: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			$json = json_decode( $body, true );
			if ( ! is_array( $json ) ) {
				throw new RuntimeException( 'Search client: HTTP 200 but invalid JSON body' );
			}
			return array(
				'hits'         => isset( $json['hits'] ) ? $json['hits'] : array(),
				'query'        => isset( $json['query'] ) ? (string) $json['query'] : $q,
				'totalHits'    => isset( $json['totalHits'] ) ? (int) $json['totalHits'] : 0,
				'processingMs' => isset( $json['processingMs'] ) ? (int) $json['processingMs'] : 0,
				'degraded'     => isset( $json['degraded'] ) ? (bool) $json['degraded'] : false,
				'strategyUsed' => isset( $json['strategyUsed'] ) ? (string) $json['strategyUsed'] : $query['strategy'],
				'raw'          => $json,
			);
		}

		throw new RuntimeException(
			sprintf( 'Search client: backend HTTP %d %s', $code, substr( $body, 0, 300 ) )
		);
	}

	/**
	 * Engine runtime params. Cached briefly via a transient; defaults on failure
	 * so the override keeps working when the SaaS is unreachable.
	 *
	 * @param string|null $scope
	 * @return array
	 */
	public function runtime_config( $scope = null ) {
		$defaults = array(
			'rankingScoreThreshold' => 0.5,
			'matchingStrategy'      => 'all',
			'debounceMs'            => 250,
			'minQueryLen'           => 2,
			'cacheTtlSeconds'       => 60,
		);

		$slug = $this->settings->get_data_source_slug( $scope );
		$url  = $this->settings->get_api_url( $scope );
		if ( ! $slug || ! $url ) {
			return $defaults;
		}

		$cache_key = 'mibizum_search_rtc_' . md5( $url . '|' . $slug );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			rtrim( $url, '/' ) . '/api/v1/runtime-config?' . http_build_query( array( 'source' => $slug ) ),
			array(
				'timeout' => 3,
				'headers' => array(
					'Accept' => 'application/json',
					'Origin' => $this->origin(),
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $json ) && isset( $json['settings'] ) && is_array( $json['settings'] ) ) {
				$merged = array_merge( $defaults, $json['settings'] );
				set_transient( $cache_key, $merged, 60 );
				return $merged;
			}
		}
		return $defaults;
	}

	/**
	 * The Origin header value (the storefront host). The backend validates it
	 * against the search key's allowed origins.
	 *
	 * @return string
	 */
	private function origin() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? ( ( is_ssl() ? 'https://' : 'http://' ) . $host ) : '';
	}

	/**
	 * @return array
	 */
	private function empty_result() {
		return array(
			'hits'         => array(),
			'query'        => '',
			'totalHits'    => 0,
			'processingMs' => 0,
			'degraded'     => false,
			'strategyUsed' => 'all',
			'raw'          => null,
		);
	}
}
