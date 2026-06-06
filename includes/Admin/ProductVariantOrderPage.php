<?php
/**
 * Counter by Therum — Product variants/options ordering page.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductVariantOrderPage extends TaxonomyOrderPage {

	protected function getTaxonomy(): string {
		return 'product_variants';
	}

	protected function getPageTitle(): string {
		return __( 'Product Variant Options Order', 'counter' );
	}

	protected function getDescription(): string {
		return __( 'Drag to reorder variant options (sizes, colors, etc). The order is used when displaying variant pickers on product pages.', 'counter' );
	}

	protected function getTerms(): array {
		// Fetch all attribute values from the database
		$pdo = \Counter\DB::pdo();
		$stmt = $pdo->prepare(
			"SELECT av.id, av.label AS name FROM attribute_values av ORDER BY av.label"
		);
		$stmt->execute();

		$terms = [];
		foreach ( $stmt->fetchAll() as $row ) {
			$terms[] = [
				'id'   => (int) $row['id'],
				'name' => (string) $row['name'],
			];
		}
		return $terms;
	}
}
