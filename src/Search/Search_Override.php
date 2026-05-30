<?php
/**
 * Native product search override, with automatic fallback.
 *
 * Ports Mibizum_Sync_Model_NativeSearchBridge to WordPress. Replaces the
 * product search results on the shop search page with Mibizum engine results,
 * so the native search box and the JS widget return the same hits.
 *
 * Mechanism: the posts_pre_query filter (WP 4.6+). Returning an array of post
 * ids short circuits the WP_Query database query (WP hydrates the ids into
 * WP_Post objects, preserving our order). We set found_posts and max_num_pages
 * for pagination.
 *
 * Never break the store (design principle #1):
 *   - Only acts on the main, front end, product search query.
 *   - Gates on is_search_enabled() (the SEARCH key), not the indexer key.
 *   - On any engine error / timeout, or an empty query, returns the untouched
 *     value so WP_Query runs native search. A degraded results page beats a
 *     broken one.
 *   - A real 0 hits is honored (no fallback): "not found" is a valid answer.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Search;

use Mibizum\Search\Http\Search_Client;
use Mibizum\Search\Settings;
use Mibizum\Search\Support\Logger;

defined( 'ABSPATH' ) || exit;

class Search_Override {

	/** Engine cap per call. */
	const ENGINE_MAX = 50;

	/** @var Search_Client */
	private $client;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct( Search_Client $client, Settings $settings, Logger $logger ) {
		$this->client   = $client;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'posts_pre_query', array( $this, 'maybe_override' ), 10, 2 );
	}

	/**
	 * @param array|null $posts Short circuit value (null lets WP_Query run).
	 * @param \WP_Query  $query
	 * @return array|null Ordered product ids to use, or the original $posts to fall back.
	 */
	public function maybe_override( $posts, $query ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $posts;
		}
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || ! $query->is_search() ) {
			return $posts;
		}
		if ( ! $this->is_product_search( $query ) ) {
			return $posts;
		}

		$scope = $this->current_scope_key();
		if ( ! $this->settings->is_search_enabled( $scope ) ) {
			return $posts;
		}

		$q = trim( (string) $query->get( 's' ) );
		if ( '' === $q ) {
			return $posts;
		}

		try {
			$per_page = (int) $query->get( 'posts_per_page' );
			if ( $per_page <= 0 ) {
				$per_page = (int) get_option( 'posts_per_page', 10 );
			}
			$per_page = min( $per_page, self::ENGINE_MAX );
			$paged    = max( 1, (int) $query->get( 'paged' ) );
			$offset   = ( $paged - 1 ) * $per_page;

			$result = $this->client->search(
				array(
					'q'        => $q,
					'limit'    => $per_page,
					'offset'   => $offset,
					'strategy' => 'auto',
				),
				$scope
			);

			$ids = array();
			foreach ( (array) $result['hits'] as $hit ) {
				if ( ! empty( $hit['product_id'] ) ) {
					$ids[] = (int) $hit['product_id'];
				} elseif ( isset( $hit['id'] ) && is_numeric( $hit['id'] ) ) {
					$ids[] = (int) $hit['id'];
				}
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );

			$total = (int) $result['totalHits'];
			if ( $total <= 0 ) {
				$total = count( $ids );
			}
			$query->found_posts   = $total;
			$query->max_num_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

			if ( empty( $ids ) ) {
				return array();
			}

			// Defense in depth: only surface ids that are genuinely PUBLISHED
			// PRODUCTS, preserving the engine's relevance order. Returning raw
			// engine ids would let a compromised, misconfigured or stale engine
			// response display private posts, other post types, or deleted ids
			// on the storefront. This by-id fetch closes that. The inner query is
			// not the main query, so this filter short circuits for it (no
			// recursion).
			$valid = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'post__in'         => $ids,
					'orderby'          => 'post__in',
					'posts_per_page'   => count( $ids ),
					'fields'           => 'ids',
					'no_found_rows'    => true,
				)
			);

			return is_array( $valid ) ? $valid : array();
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'search override engine failed, falling back to native: ' . $e->getMessage(),
				array( 'query' => $q )
			);
			return $posts;
		}
	}

	/**
	 * Is this query a product search? Acts when the post type is (or includes)
	 * 'product'. A plain blog search (post type post/empty) is left to WordPress.
	 *
	 * @param \WP_Query $query
	 * @return bool
	 */
	private function is_product_search( $query ) {
		$post_type = $query->get( 'post_type' );
		if ( is_array( $post_type ) ) {
			return in_array( 'product', $post_type, true );
		}
		return 'product' === $post_type;
	}

	/**
	 * Current scope key for the front end request (language aware). Returns null
	 * for the default scope; Settings falls back to default for unset language
	 * keys.
	 *
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
