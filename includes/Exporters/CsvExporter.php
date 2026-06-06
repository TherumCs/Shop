<?php
/**
 * Counter by Therum — generic CSV exporter.
 *
 * One row per product. For variant-bearing products, also emits one
 * row per variant with the parent's title + variant attribute columns
 * filled in. Round-trips cleanly through CsvImporter.
 */

namespace Counter\Exporters;

use Counter\Models\Product;
use Counter\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CsvExporter implements Exporter {

	private const HEADERS = [
		'id', 'sku', 'title', 'status', 'description',
		'price', 'compare_at_price', 'cost', 'stock_qty',
		'is_pod', 'pod_provider', 'pod_product_id', 'pod_variant_id',
		'color', 'size', 'material', 'image_url',
	];

	public function __construct(
		private readonly CatalogReader $reader,
		private readonly ProductRepository $products,
	) {}

	public function id(): string          { return 'csv'; }
	public function displayName(): string { return 'CSV'; }
	public function mimeType(): string    { return 'text/csv'; }
	public function extension(): string   { return 'csv'; }

	public function export( ExportQuery $query ): ExportResult {
		$h = fopen( 'php://memory', 'r+' );
		fputcsv( $h, self::HEADERS );

		$count = 0;
		foreach ( $this->reader->walk( $query ) as $product ) {
			$count++;
			$this->writeProductRows( $h, $product );
		}

		rewind( $h );
		$body = (string) stream_get_contents( $h );
		fclose( $h );

		return ExportResult::string(
			body:     $body,
			filename: 'catalog-' . gmdate( 'Y-m-d' ) . '.csv',
			mime:     $this->mimeType(),
			count:    $count,
		);
	}

	/**
	 * @param resource $h
	 */
	private function writeProductRows( $h, Product $product ): void {
		if ( ! $product->hasVariants ) {
			fputcsv( $h, $this->productRow( $product, null ) );
			return;
		}

		// Query variant IDs in order, then batch-fetch all variants at once.
		// This replaces the N+1 pattern from earlier versions.
		$pdo = \Counter\DB::pdo();
		$stmt = $pdo->prepare( "SELECT id FROM product_variants WHERE product_id = :p ORDER BY position ASC" );
		$stmt->execute( [ ':p' => $product->id ] );
		$variant_ids = array_map( 'intval', array_column( $stmt->fetchAll(), 'id' ) );

		if ( ! $variant_ids ) {
			fputcsv( $h, $this->productRow( $product, null ) );
			return;
		}

		// Batch fetch all variants for this product in one query
		$variants = $this->products->findVariants( $variant_ids );

		foreach ( $variant_ids as $vid ) {
			$variant = $variants[ $vid ] ?? null;
			if ( $variant === null ) continue;
			fputcsv( $h, $this->productRow( $product, $variant ) );
		}
	}

	private function productRow( Product $product, ?\Counter\Models\Variant $variant ): array {
		$options = $variant?->meta['options'] ?? [];
		$image_url = $product->primaryImageId !== null
			? (string) wp_get_attachment_image_url( $product->primaryImageId, 'large' )
			: '';

		return [
			$variant?->id ?? $product->id,
			$variant?->sku  ?? $product->sku,
			$product->title,
			$product->status,
			$product->description,
			$this->money( $variant?->price ?? $product->price ),
			$this->money( $variant?->compareAtPrice ?? $product->compareAtPrice ),
			$this->money( $variant?->cost ?? $product->cost ),
			$variant?->stockQty ?? $product->stockQty ?? '',
			$product->isPod ? '1' : '0',
			$variant?->podProvider ?? '',
			$variant?->podProductId ?? '',
			$variant?->podVariantId ?? '',
			$options['color']    ?? $options['Color']    ?? '',
			$options['size']     ?? $options['Size']     ?? '',
			$options['material'] ?? $options['Material'] ?? '',
			$image_url,
		];
	}

	private function money( ?\Counter\Money $m ): string {
		if ( $m === null ) return '';
		return number_format( $m->minor / 100, 2, '.', '' );
	}
}
