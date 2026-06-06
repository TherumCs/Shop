<?php
/**
 * Counter by Therum — Product categories ordering page.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductCategoryOrderPage extends TaxonomyOrderPage {

	protected function getTaxonomy(): string {
		return 'product_categories';
	}

	protected function getPageTitle(): string {
		return __( 'Product Category Order', 'counter' );
	}

	protected function getDescription(): string {
		return __( 'Drag to reorder product categories. The order is used when displaying category navigation and filters.', 'counter' );
	}

	protected function getTerms(): array {
		// For MVP, return empty — categories created via attributes system
		// In future: integrate with WooCommerce categories or custom taxonomy
		return [];
	}
}
