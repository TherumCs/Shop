<?php
/**
 * Shop by Therum — CatalogReader.
 *
 * Shared product walker used by every exporter. Hides the SQL/Woo
 * difference behind a single generator — exporters iterate over
 * Product DTOs without knowing which catalog source they came from.
 *
 * Yields one Product at a time so the whole catalog isn't held in
 * memory for big stores.
 */

namespace Shop\Exporters;

use Shop\DB;
use Shop\Models\Product;
use Shop\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CatalogReader {

	public function __construct(
		private readonly ProductRepository $products,
	) {}

	/**
	 * @return \Generator<int, Product>
	 */
	public function walk( ExportQuery $q ): \Generator {
		// Native catalog → query products table directly. For Woo catalog
		// the same id list flows through ProductRepository::findById,
		// which the container has already pointed at the right impl.
		if ( $q->ids ) {
			foreach ( $q->ids as $id ) {
				$p = $this->products->findById( (int) $id );
				if ( $p !== null ) yield $p;
			}
			return;
		}

		// Native-source filtering pulls ids from our SQLite first, then
		// hydrates through the repo (so it auto-uses request-scoped cache
		// when in Woo mode).
		$pdo = DB::pdo();
		$where = [];
		$bind  = [];
		if ( $q->status !== null && $q->status !== '' ) {
			$where[] = 'status = :status';
			$bind[':status'] = $q->status;
		}
		if ( $q->search !== null && $q->search !== '' ) {
			$where[] = '(title LIKE :s OR sku LIKE :s)';
			$bind[':s'] = '%' . $q->search . '%';
		}

		$sql = 'SELECT id FROM products';
		if ( $where ) $sql .= ' WHERE ' . implode( ' AND ', $where );
		$sql .= ' ORDER BY id ASC LIMIT ' . (int) $q->limit . ' OFFSET ' . (int) $q->offset;

		$stmt = $pdo->prepare( $sql );
		$stmt->execute( $bind );
		while ( $row = $stmt->fetch() ) {
			$p = $this->products->findById( (int) $row['id'] );
			if ( $p !== null ) yield $p;
		}
	}
}
