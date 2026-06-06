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
		// Counter uses attributes as the primary category/grouping system
		// (Color, Size, Material, etc). Fetch all attributes for reordering.
		$pdo = \Counter\DB::pdo();
		$stmt = $pdo->prepare(
			"SELECT id, name FROM attributes ORDER BY name"
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
