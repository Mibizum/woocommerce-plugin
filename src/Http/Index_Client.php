<?php
/**
 * Index API client (write scope, INDEXER key).
 *
 * Ports Mibizum_Sync_Model_Adapter_Mibizum. Everything goes through the SaaS
 * API with a Bearer key; there is no direct engine access. Uses the WordPress
 * HTTP API (wp_remote_request) with explicit timeouts.
 *
 * Endpoints (confirmed from the Magento module, do not change without backend
 * sign off):
 *   POST   {api_url}/api/v1/index            body { documents:[...], source:<slug> } -> { accepted, taskUid }
 *   DELETE {api_url}/api/v1/index/{urlenc(id)}?source=<slug>
 *   POST   {api_url}/api/v1/index/settings   body { searchableAttributes, filterableAttributes, sortableAttributes, source }
 *   POST   {api_url}/api/v1/sync-runs        best effort telemetry, 404 is silenced
 *
 * Per scope: the api_url, indexer key and slug are read from Settings for the
 * scope key the worker is processing.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Http;

use Mibizum\Search\Settings;
use Mibizum\Search\Support\Logger;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

class Index_Client {

	/** Backend cap per POST. Callers split into chunks below this. */
	const MAX_BATCH_SIZE = 500;

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
	 * Publish documents (idempotent upsert by document id).
	 *
	 * @param array       $documents Each a JSON serializable array with an `id`.
	 * @param string|null $scope     Scope key.
	 * @return array{accepted:int,taskUid:?string}
	 * @throws RuntimeException on non 2xx or network failure.
	 */
	public function index_documents( array $documents, $scope = null ) {
		if ( empty( $documents ) ) {
			return array(
				'accepted' => 0,
				'taskUid'  => null,
			);
		}
		if ( count( $documents ) > self::MAX_BATCH_SIZE ) {
			throw new RuntimeException(
				sprintf( 'index_documents: batch too large (%d > %d)', count( $documents ), self::MAX_BATCH_SIZE )
			);
		}

		$payload = array( 'documents' => array_values( $documents ) );
		$slug    = $this->settings->get_data_source_slug( $scope );
		if ( $slug ) {
			$payload['source'] = $slug;
		}

		$url = $this->settings->get_api_url( $scope ) . '/api/v1/index';
		list( $code, $body ) = $this->request( 'POST', $url, $scope, wp_json_encode( $payload ) );

		if ( 200 === $code || 202 === $code ) {
			$json = json_decode( (string) $body, true );
			return array(
				'accepted' => isset( $json['accepted'] ) ? (int) $json['accepted'] : count( $documents ),
				'taskUid'  => isset( $json['taskUid'] ) ? $json['taskUid'] : null,
			);
		}

		throw new RuntimeException(
			sprintf( 'index_documents: backend HTTP %d %s', $code, substr( (string) $body, 0, 300 ) )
		);
	}

	/**
	 * Delete documents by id (SKU). Iterates (no batch delete in v1). Idempotent.
	 * Throws only if ALL fail; logs a warning on partial failure.
	 *
	 * @param string[]    $ids
	 * @param string|null $scope
	 * @return int Successful deletes.
	 * @throws RuntimeException
	 */
	public function delete_documents_by_ids( array $ids, $scope = null ) {
		if ( empty( $ids ) ) {
			return 0;
		}
		$slug    = $this->settings->get_data_source_slug( $scope );
		$base    = $this->settings->get_api_url( $scope ) . '/api/v1/index/';
		$ok      = 0;
		$errors  = array();

		foreach ( $ids as $id ) {
			$id_str = (string) $id;
			if ( '' === $id_str ) {
				continue;
			}
			$url = $base . rawurlencode( $id_str );
			if ( $slug ) {
				$url .= '?source=' . rawurlencode( $slug );
			}
			try {
				list( $code, $body ) = $this->request( 'DELETE', $url, $scope, null );
				if ( 200 === $code || 202 === $code || 404 === $code ) {
					++$ok;
				} else {
					$errors[] = "id={$id_str}: HTTP {$code}";
				}
			} catch ( RuntimeException $e ) {
				$errors[] = "id={$id_str}: " . $e->getMessage();
			}
		}

		if ( 0 === $ok && ! empty( $errors ) ) {
			throw new RuntimeException( 'delete_documents_by_ids: ALL failed: ' . implode( ' | ', array_slice( $errors, 0, 5 ) ) );
		}
		if ( ! empty( $errors ) ) {
			$this->logger->warning( sprintf( 'delete_documents_by_ids: %d OK, %d failed', $ok, count( $errors ) ) );
		}
		return $ok;
	}

	/**
	 * Apply the searchable/filterable/sortable schema for a data source.
	 *
	 * @param array       $schema From Schema::build_search_schema().
	 * @param string|null $scope
	 * @return array Backend response.
	 * @throws RuntimeException
	 */
	public function apply_settings( array $schema, $scope = null ) {
		$payload = array(
			'searchableAttributes' => isset( $schema['searchable'] ) ? $schema['searchable'] : array(),
			'filterableAttributes' => isset( $schema['filterable'] ) ? $schema['filterable'] : array(),
			'sortableAttributes'   => isset( $schema['sortable'] ) ? $schema['sortable'] : array(),
		);
		$slug = $this->settings->get_data_source_slug( $scope );
		if ( $slug ) {
			$payload['source'] = $slug;
		}

		$url = $this->settings->get_api_url( $scope ) . '/api/v1/index/settings';
		list( $code, $body ) = $this->request( 'POST', $url, $scope, wp_json_encode( $payload ) );

		if ( 200 === $code || 202 === $code ) {
			$json = json_decode( (string) $body, true );
			return is_array( $json ) ? $json : array();
		}
		throw new RuntimeException(
			sprintf( 'apply_settings: backend HTTP %d %s', $code, substr( (string) $body, 0, 300 ) )
		);
	}

	/**
	 * Best effort sync run telemetry. Never throws (404 and errors swallowed).
	 *
	 * @param array       $payload
	 * @param string|null $scope
	 * @return void
	 */
	public function report_sync_run( array $payload, $scope = null ) {
		if ( ! isset( $payload['data_source_slug'] ) ) {
			$slug = $this->settings->get_data_source_slug( $scope );
			if ( $slug ) {
				$payload['data_source_slug'] = $slug;
			}
		}
		try {
			$url = $this->settings->get_api_url( $scope ) . '/api/v1/sync-runs';
			$this->request( 'POST', $url, $scope, wp_json_encode( $payload ), 3 );
		} catch ( RuntimeException $e ) {
			$this->logger->warning( 'report_sync_run failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Perform an authenticated request with the indexer key. Returns [code, body].
	 * Throws on a transport error (not on a status code).
	 *
	 * @param string      $method
	 * @param string      $url
	 * @param string|null $scope
	 * @param string|null $body
	 * @param int|null    $timeout Override timeout in seconds.
	 * @return array{0:int,1:string}
	 * @throws RuntimeException
	 */
	private function request( $method, $url, $scope, $body, $timeout = null ) {
		$key = $this->settings->get_indexer_key( $scope );
		if ( '' === $key ) {
			throw new RuntimeException( 'Mibizum index client: indexer key not configured' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Accept'        => 'application/json',
			'User-Agent'    => 'Mibizum-Search-WooCommerce/' . MIBIZUM_SEARCH_VERSION,
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => null !== $timeout ? (int) $timeout : $this->settings->get_timeout_seconds(),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Network error: ' . $response->get_error_message() );
		}
		return array(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
		);
	}
}
