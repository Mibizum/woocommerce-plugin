<?php
/**
 * Index document schema (the document contract).
 *
 * Single source of truth for which fields the engine treats as searchable,
 * filterable and sortable. Ports Mibizum_Sync_Model_Config_Schema so the
 * WooCommerce documents are interchangeable with the Magento ones in the same
 * Mibizum tenant.
 *
 * Two kinds of fields:
 *   1. Synthetic fields (declared here): computed in Product_Mapper from the
 *      product (price, in_stock, categories, badges, ...). Part of the contract.
 *   2. Merchant attributes (extensible): WooCommerce product attributes the
 *      merchant marks searchable/filterable/sortable in settings.
 *
 * @package Mibizum\Search
 */

namespace Mibizum\Search;

defined( 'ABSPATH' ) || exit;

class Schema {

	/**
	 * Highest weight synthetic searchable fields. List order IS priority.
	 * Descriptions are intentionally NOT searchable (kept in the document but
	 * not searched), matching the Magento module.
	 *
	 * @return string[]
	 */
	public static function synthetic_searchable_high() {
		return array( 'name' );
	}

	/**
	 * Lowest weight synthetic searchable fields, appended last.
	 *
	 * @return string[]
	 */
	public static function synthetic_searchable_low() {
		return array( 'sku', 'categories' );
	}

	/**
	 * Synthetic filterable fields. `id` must be filterable for the panel's
	 * by-ids re-hydration endpoint.
	 *
	 * @return string[]
	 */
	public static function synthetic_filterable() {
		return array( 'id', 'in_stock', 'in_offer', 'categories', 'doc_type', 'is_visible' );
	}

	/**
	 * Synthetic sortable fields.
	 *
	 * @return string[]
	 */
	public static function synthetic_sortable() {
		return array( 'price', 'name', 'created_at' );
	}

	/**
	 * Merge synthetic fields with the merchant enabled attributes into the full
	 * engine settings payload, sent via POST /api/v1/index/settings.
	 *
	 * @return array{searchable:string[],filterable:string[],sortable:string[]}
	 */
	public static function build_search_schema() {
		// TODO: append enabled product attributes (searchable/filterable/sortable)
		// to the synthetic lists, then unique. See Config_Schema::buildSearchSchema.
		return array(
			'searchable' => array_values( array_unique( array_merge( self::synthetic_searchable_high(), self::synthetic_searchable_low() ) ) ),
			'filterable' => self::synthetic_filterable(),
			'sortable'   => self::synthetic_sortable(),
		);
	}
}
