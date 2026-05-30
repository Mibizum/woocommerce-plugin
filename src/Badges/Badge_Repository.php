<?php
/**
 * Badge configuration and resolution.
 *
 * Ports the three Magento badge families. Labels and visual config travel inside
 * the indexed document (the panel controls final styling). Master toggles live
 * in Settings.
 *
 *   1. System badges: computed from product state. out_of_stock,
 *      low_stock (threshold), on_sale (is_on_sale), new (created within N days),
 *      featured (is_featured). Labels fall back to translated defaults.
 *   2. Category badges (Magento "nature"): a badge attached to a product
 *      category term, optionally including descendants. At most one per product
 *      (lowest sort_priority wins). Field: category_badge.
 *   3. Attribute badges: a badge driven by a product attribute term (pa_*
 *      taxonomy), with an optional category filter. Zero or more per product.
 *      Field: attribute_badges[].
 *
 * Category and attribute definitions live in custom tables created by the
 * Activator. The in panel editor for them is a later increment; resolution and
 * the indexing path are implemented here and degrade to empty when no rows.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Badges;

use Mibizum\Search\Settings;

defined( 'ABSPATH' ) || exit;

class Badge_Repository {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/** @return string Category badge definitions table. */
	public static function category_table() {
		global $wpdb;
		return $wpdb->prefix . 'mibizum_search_category_badges';
	}

	/** @return string Category badge to term assignments table. */
	public static function category_terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'mibizum_search_category_badge_terms';
	}

	/** @return string Attribute badge definitions table. */
	public static function attribute_table() {
		global $wpdb;
		return $wpdb->prefix . 'mibizum_search_attribute_badges';
	}

	/**
	 * System badge labels resolved for a product (the ones that apply).
	 *
	 * @param \WC_Product $product
	 * @return array[] [ { code, label } ]
	 */
	public function system_badges_for( $product ) {
		if ( ! $this->settings->show_system_badges() ) {
			return array();
		}
		$cfg = $this->settings->get_system_badges_config();
		$out = array();

		if ( $cfg['out_of_stock']['enabled'] && ! $product->is_in_stock() ) {
			$out[] = array(
				'code'  => 'out_of_stock',
				'label' => $cfg['out_of_stock']['label'],
			);
		} elseif ( $cfg['low_stock']['enabled'] && $product->is_in_stock() && $product->managing_stock() ) {
			$qty = $product->get_stock_quantity();
			if ( null !== $qty && $qty > 0 && $qty <= (int) $cfg['low_stock']['threshold'] ) {
				$out[] = array(
					'code'  => 'low_stock',
					'label' => $cfg['low_stock']['label'],
				);
			}
		}

		if ( $cfg['in_offer']['enabled'] && $product->is_on_sale() ) {
			$out[] = array(
				'code'  => 'in_offer',
				'label' => $cfg['in_offer']['label'],
			);
		}

		if ( $cfg['new']['enabled'] ) {
			$created = $product->get_date_created();
			if ( $created ) {
				$age_days = ( time() - (int) $created->getTimestamp() ) / DAY_IN_SECONDS;
				if ( $age_days <= (int) $cfg['new']['days'] ) {
					$out[] = array(
						'code'  => 'new',
						'label' => $cfg['new']['label'],
					);
				}
			}
		}

		if ( $cfg['featured']['enabled'] && $product->is_featured() ) {
			$out[] = array(
				'code'  => 'featured',
				'label' => $cfg['featured']['label'],
			);
		}

		return $out;
	}

	/**
	 * Winning category badge for a product, or null. Lowest sort_priority wins.
	 *
	 * @param \WC_Product $product
	 * @return array|null
	 */
	public function category_badge_for( $product ) {
		if ( ! $this->settings->show_category_badges() ) {
			return null;
		}
		$cat_ids = $product->get_category_ids();
		if ( empty( $cat_ids ) ) {
			return null;
		}
		$index = $this->category_badge_index();
		if ( empty( $index ) ) {
			return null;
		}
		$best = null;
		foreach ( $cat_ids as $cid ) {
			$cid = (int) $cid;
			if ( isset( $index[ $cid ] ) ) {
				if ( null === $best || $index[ $cid ]['sort_priority'] < $best['sort_priority'] ) {
					$best = $index[ $cid ];
				}
			}
		}
		return $best;
	}

	/**
	 * Attribute badges matching a product.
	 *
	 * @param \WC_Product $product
	 * @return array[]
	 */
	public function attribute_badges_for( $product ) {
		if ( ! $this->settings->show_attribute_badges() ) {
			return array();
		}
		$rows = $this->attribute_badge_defs();
		if ( empty( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$taxonomy = (string) $row['taxonomy'];
			$terms    = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$match = (string) $row['match_value'];
			if ( '' !== $match && ! in_array( $match, $terms, true ) ) {
				continue;
			}
			$out[] = array(
				'label'         => (string) $row['label'],
				'color_hex'     => (string) $row['color_hex'],
				'sort_priority' => (int) $row['sort_priority'],
			);
		}
		return $out;
	}

	/**
	 * Enqueue all products affected by a badge definition for reindex (after a
	 * badge save/delete).
	 *
	 * @param string $type 'category' | 'attribute'
	 * @param int    $badge_id
	 * @return int
	 */
	public function enqueue_affected_products( $type, $badge_id ) {
		// TODO: resolve affected product ids and enqueue. Wired with the in panel
		// badge editor (next increment). A full reindex already refreshes labels.
		return 0;
	}

	// --- Internals -------------------------------------------------------

	/**
	 * Build a map of term_id => winning category badge, expanding descendants.
	 *
	 * @return array<int,array>
	 */
	private function category_badge_index() {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$defs  = self::category_table();
		$terms = self::category_terms_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT d.id, d.label, d.color_hex, d.text_color_hex, d.position, d.shape, d.sort_priority,
				t.term_id, t.include_children
			 FROM {$defs} d
			 JOIN {$terms} t ON t.badge_id = d.id
			 WHERE d.enabled = 1
			 ORDER BY d.sort_priority ASC, d.id ASC",
			ARRAY_A
		);

		$map = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$badge = array(
					'id'            => (int) $r['id'],
					'label'         => (string) $r['label'],
					'color_hex'     => (string) $r['color_hex'],
					'sort_priority' => (int) $r['sort_priority'],
				);
				$term_ids = array( (int) $r['term_id'] );
				if ( ! empty( $r['include_children'] ) ) {
					$children = get_term_children( (int) $r['term_id'], 'product_cat' );
					if ( ! is_wp_error( $children ) ) {
						foreach ( $children as $cid ) {
							$term_ids[] = (int) $cid;
						}
					}
				}
				foreach ( array_unique( $term_ids ) as $tid ) {
					if ( ! isset( $map[ $tid ] ) ) {
						$map[ $tid ] = $badge;
					}
				}
			}
		}
		$cache = $map;
		return $cache;
	}

	/**
	 * @return array[]
	 */
	private function attribute_badge_defs() {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$table = self::attribute_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY sort_priority ASC, id ASC", ARRAY_A );
		$cache = $rows ? $rows : array();
		return $cache;
	}
}
