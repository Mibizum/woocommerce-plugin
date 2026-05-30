<?php
/**
 * Product to document mapper.
 *
 * Ports Mibizum_Sync_Model_Indexer_ProductMapper to WooCommerce. Converts a
 * WC_Product into the Mibizum document. Returns null when the product must NOT
 * be indexed (not published, hidden) so the worker removes it from that scope.
 *
 * Document id = SKU when present, else "wc-{id}" (WooCommerce SKUs are
 * optional). The numeric WooCommerce id is always in `product_id`, which the
 * search override uses to map hits back to posts. The document shape matches the
 * Magento module so both platforms feed the same index contract.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search\Indexer;

use Mibizum\Search\Badges\Badge_Repository;

defined( 'ABSPATH' ) || exit;

class Product_Mapper {

	/** @var Badge_Repository */
	private $badges;

	/**
	 * @param Badge_Repository $badges
	 */
	public function __construct( Badge_Repository $badges ) {
		$this->badges = $badges;
	}

	/**
	 * Deterministic document id from sku/product id. Used by both the mapper and
	 * the delete path so deletes hit the same document we indexed.
	 *
	 * @param string $sku
	 * @param int    $product_id
	 * @return string
	 */
	public static function document_id( $sku, $product_id ) {
		$sku = trim( (string) $sku );
		return '' !== $sku ? $sku : 'wc-' . (int) $product_id;
	}

	/**
	 * Map a product in the current scope context.
	 *
	 * @param \WC_Product $product
	 * @return array|null Document, or null to skip (and remove from the index).
	 */
	public function map( $product ) {
		if ( ! $product || ! $product->get_id() ) {
			return null;
		}
		if ( 'publish' !== $product->get_status() ) {
			return null;
		}
		$visibility = $product->get_catalog_visibility(); // visible | catalog | search | hidden.
		if ( 'hidden' === $visibility ) {
			return null;
		}

		$allowed_types = array( 'simple', 'variable', 'grouped', 'external' );
		if ( ! in_array( $product->get_type(), $allowed_types, true ) ) {
			return null;
		}

		$prices     = $this->price_data( $product );
		$sku        = (string) $product->get_sku();
		$product_id = (int) $product->get_id();

		$visible_for_search = in_array( $visibility, array( 'visible', 'search' ), true );
		$has_price          = ( (float) $prices['price'] > 0 ) || ( (float) $prices['price_max'] > 0 );
		$is_visible         = $visible_for_search && $has_price;

		$created    = $product->get_date_created();
		$created_ts = $created ? (int) $created->getTimestamp() : 0;

		$doc = array(
			'id'                => self::document_id( $sku, $product_id ),
			'doc_type'          => 'product',
			'sku'               => $sku,
			'product_id'        => $product_id,
			'name'              => (string) $product->get_name(),
			'short_description' => $this->clean_text( $product->get_short_description() ),
			'description'       => $this->clean_text( $product->get_description() ),
			'url'               => (string) get_permalink( $product_id ),
			'image_url'         => $this->image_url( $product ),
			'price'             => $prices['price'],
			'price_orig'        => $prices['price_orig'],
			'price_min'         => $prices['price_min'],
			'price_max'         => $prices['price_max'],
			'in_offer'          => (bool) $product->is_on_sale(),
			'in_stock'          => (bool) $product->is_in_stock(),
			'stock_qty'         => null !== $product->get_stock_quantity() ? (float) $product->get_stock_quantity() : 0.0,
			'featured'          => (bool) $product->is_featured(),
			'visibility'        => $this->visibility_code( $visibility ),
			'is_visible'        => $is_visible,
			'type_id'           => (string) $product->get_type(),
			'created_at'        => $created_ts ? gmdate( 'c', $created_ts ) : null,
			'created_at_ts'     => $created_ts,
			'categories'        => $this->category_names( $product ),
		);

		// Badges (labels travel inside the document).
		$system = $this->badges->system_badges_for( $product );
		if ( ! empty( $system ) ) {
			$doc['system_badges'] = $system;
		}
		$category_badge = $this->badges->category_badge_for( $product );
		if ( $category_badge ) {
			$doc['category_badge'] = $category_badge;
		}
		$attribute_badges = $this->badges->attribute_badges_for( $product );
		if ( ! empty( $attribute_badges ) ) {
			$doc['attribute_badges'] = $attribute_badges;
		}

		// Variation chips (formats) for variable / grouped products.
		$formats = $this->formats( $product );
		if ( ! empty( $formats ) ) {
			$doc['formats'] = $formats;
		}

		return $doc;
	}

	// --- Helpers ---------------------------------------------------------

	/**
	 * Map the WooCommerce catalog visibility to the Magento style integer so the
	 * contract field is consistent across platforms.
	 *
	 * @param string $visibility
	 * @return int hidden=1, catalog=2, search=3, visible=4.
	 */
	private function visibility_code( $visibility ) {
		switch ( $visibility ) {
			case 'hidden':
				return 1;
			case 'catalog':
				return 2;
			case 'search':
				return 3;
			default:
				return 4;
		}
	}

	/**
	 * @param \WC_Product $product
	 * @return array{price:float,price_orig:float,price_min:float,price_max:float}
	 */
	private function price_data( $product ) {
		$round = static function ( $v ) {
			return (float) number_format( (float) $v, 2, '.', '' );
		};

		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( true );
			$active = ! empty( $prices['price'] ) ? array_map( 'floatval', $prices['price'] ) : array();
			$reg    = ! empty( $prices['regular_price'] ) ? array_map( 'floatval', $prices['regular_price'] ) : array();
			$min    = $active ? min( $active ) : 0.0;
			$max    = $active ? max( $active ) : 0.0;
			return array(
				'price'      => $round( $min ),
				'price_orig' => $round( $reg ? min( $reg ) : $min ),
				'price_min'  => $round( $min ),
				'price_max'  => $round( $max ),
			);
		}

		if ( $product->is_type( 'grouped' ) ) {
			$children = $product->get_children();
			$values   = array();
			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && '' !== $child->get_price() ) {
					$values[] = (float) $child->get_price();
				}
			}
			$min = $values ? min( $values ) : 0.0;
			$max = $values ? max( $values ) : 0.0;
			return array(
				'price'      => $round( $min ),
				'price_orig' => $round( $min ),
				'price_min'  => $round( $min ),
				'price_max'  => $round( $max ),
			);
		}

		$price = '' !== $product->get_price() ? (float) $product->get_price() : 0.0;
		$reg   = '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : $price;
		return array(
			'price'      => $round( $price ),
			'price_orig' => $round( $reg ),
			'price_min'  => $round( $price ),
			'price_max'  => $round( $price ),
		);
	}

	/**
	 * @param \WC_Product $product
	 * @return string
	 */
	private function image_url( $product ) {
		$image_id = (int) $product->get_image_id();
		if ( ! $image_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $image_id, 'full' );
		return $url ? (string) $url : '';
	}

	/**
	 * @param \WC_Product $product
	 * @return string[]
	 */
	private function category_names( $product ) {
		$names = array();
		foreach ( $product->get_category_ids() as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Variation chips for variable/grouped products: { label, in_stock, qty }.
	 *
	 * @param \WC_Product $product
	 * @return array[]
	 */
	private function formats( $product ) {
		if ( ! $product->is_type( 'variable' ) && ! $product->is_type( 'grouped' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $product->get_children(), 0, 30 ) as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$label = '';
			if ( is_callable( array( $child, 'get_attribute_summary' ) ) ) {
				$label = (string) $child->get_attribute_summary();
			}
			if ( '' === $label ) {
				$label = (string) $child->get_name();
			}
			if ( '' === $label ) {
				continue;
			}
			$qty   = $child->get_stock_quantity();
			$out[] = array(
				'label'    => $label,
				'in_stock' => (bool) $child->is_in_stock(),
				'qty'      => null !== $qty ? (int) $qty : 0,
			);
		}
		return $out;
	}

	/**
	 * Normalize rich text to plain text for the document (stored, not searched).
	 *
	 * @param string $html
	 * @return string
	 */
	private function clean_text( $html ) {
		if ( null === $html || '' === $html ) {
			return '';
		}
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}
}
