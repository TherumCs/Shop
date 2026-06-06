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
		// Fetch all attribute values (color, size, etc) from the database
		$pdo = \Counter\DB::pdo();
		$stmt = $pdo->prepare(
			"SELECT av.id, av.value AS name, a.slug AS attribute_slug
			 FROM attribute_values av
			 JOIN attributes a ON av.attribute_id = a.id
			 ORDER BY a.name, av.value"
		);
		$stmt->execute();

		$terms = [];
		foreach ( $stmt->fetchAll() as $row ) {
			$terms[] = [
				'id'   => (int) $row['id'],
				'name' => $row['attribute_slug'] . ': ' . (string) $row['name'],
			];
		}
		return $terms;
	}
}
