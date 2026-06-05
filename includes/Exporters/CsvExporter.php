<?php
/**
 * Shop by Therum — generic CSV exporter.
 *
 * One row per product. For variant-bearing products, also emits one
 * row per variant with the parent's title + variant attribute columns
 * filled in. Round-trips cleanly through CsvImporter.
 */

namespace Shop\Exporters;

use Shop\Models\Product;
use Shop\Repositories\ProductRepository;

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

		// TODO: when AttributeRepository can yield variants without an
		// extra query per product, batch this. For v1, one row per
		// variant via a follow-up findVariant loop is plenty.
		// Variants come from ProductRepository::findVariant — but we
		// don't have a "list variants" method yet on the read interface;
		// the CSV importer can still consume the single-row form and
		// admin can rebuild variants from that. Native catalog: hit the
		// table directly.
		$pdo = \Shop\DB::pdo();
		$stmt = $pdo->prepare( "SELECT id FROM product_variants WHERE product_id = :p ORDER BY position ASC" );
		$stmt->execute( [ ':p' => $product->id ] );
		$variant_ids = array_map( 'intval', array_column( $stmt->fetchAll(), 'id' ) );
		if ( ! $variant_ids ) {
			fputcsv( $h, $this->productRow( $product, null ) );
			return;
		}
		foreach ( $variant_ids as $vid ) {
			$variant = $this->products->findVariant( $vid );
			if ( $variant === null ) continue;
			fputcsv( $h, $this->productRow( $product, $variant ) );
		}
	}

	private function productRow( Product $product, ?\Shop\Models\Variant $variant ): array {
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

	private function money( ?\Shop\Money $m ): string {
		if ( $m === null ) return '';
		return number_format( $m->minor / 100, 2, '.', '' );
	}
}
