<?php
/**
 * Shop by Therum — Meta (Facebook + Instagram) Catalog CSV feed.
 *
 * Spec: https://www.facebook.com/business/help/120325381656392
 *
 * Required columns:
 *   id, title, description, availability, condition, price, link,
 *   image_link, brand
 *
 * Optional (we emit them when we have them):
 *   sale_price, item_group_id, color, size, gtin, mpn
 *
 * Vendor-normalized color/size via the dictionary, same as Google
 * Shopping feed.
 */

namespace Shop\Exporters;

use Shop\Models\Product;
use Shop\Models\Variant;
use Shop\Repositories\ProductRepository;
use Shop\Services\VendorDictionaryService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class MetaCatalogFeed implements Exporter {

	private const HEADERS = [
		'id', 'title', 'description', 'availability', 'condition',
		'price', 'sale_price', 'link', 'image_link', 'brand',
		'item_group_id', 'color', 'size',
	];

	public function __construct(
		private readonly CatalogReader $reader,
		private readonly ProductRepository $products,
		private readonly VendorDictionaryService $dictionary,
	) {}

	public function id(): string          { return 'meta-catalog'; }
	public function displayName(): string { return 'Meta Catalog (CSV)'; }
	public function mimeType(): string    { return 'text/csv'; }
	public function extension(): string   { return 'csv'; }

	public function export( ExportQuery $query ): ExportResult {
		$site_url   = $query->siteUrl ?? home_url();
		$brand_def  = (string) get_bloginfo( 'name' );

		$h = fopen( 'php://memory', 'r+' );
		fputcsv( $h, self::HEADERS );

		$count = 0;
		foreach ( $this->reader->walk( $query ) as $product ) {
			if ( $product->hasVariants ) {
				foreach ( $this->variantsOf( $product ) as $variant ) {
					fputcsv( $h, $this->row( $product, $variant, $site_url, $brand_def ) );
					$count++;
				}
			} else {
				fputcsv( $h, $this->row( $product, null, $site_url, $brand_def ) );
				$count++;
			}
		}

		rewind( $h );
		$body = (string) stream_get_contents( $h );
		fclose( $h );

		return ExportResult::string(
			body:     $body,
			filename: 'meta-catalog-' . gmdate( 'Y-m-d' ) . '.csv',
			mime:     $this->mimeType(),
			count:    $count,
		);
	}

	private function row( Product $product, ?Variant $variant, string $site_url, string $brand_def ): array {
		$id     = $variant?->sku ?? $product->sku ?? ( 'tp-' . $product->id . ( $variant ? '-v' . $variant->id : '' ) );
		$price  = $variant?->price ?? $product->price;
		$compare = $variant?->compareAtPrice ?? $product->compareAtPrice;
		$avail  = $product->isPurchasable() ? 'in stock' : 'out of stock';
		$image  = $variant?->imageId
			? wp_get_attachment_image_url( $variant->imageId, 'large' )
			: ( $product->primaryImageId ? wp_get_attachment_image_url( $product->primaryImageId, 'large' ) : '' );

		$color = '';
		$size  = '';
		if ( $variant !== null ) {
			$opts = $variant->meta['options'] ?? [];
			$raw_color = (string) ( $opts['Color'] ?? $opts['color'] ?? '' );
			$raw_size  = (string) ( $opts['Size']  ?? $opts['size']  ?? '' );
			$color = ( $raw_color !== '' && $variant->podProvider )
				? ( $this->dictionary->translate( $variant->podProvider, 'color', $raw_color ) ?? $raw_color )
				: $raw_color;
			$size = ( $raw_size !== '' && $variant->podProvider )
				? ( $this->dictionary->translate( $variant->podProvider, 'size', $raw_size ) ?? $raw_size )
				: $raw_size;
		}

		return [
			$id,
			$product->title,
			wp_strip_all_tags( $product->description ?? $product->shortDescription ?? '' ),
			$avail,
			'new',
			$price !== null    ? number_format( $price->minor / 100, 2, '.', '' ) . ' ' . $price->currency : '',
			( $compare !== null && $price !== null && $compare->greaterThan( $price ) )
				? number_format( $price->minor / 100, 2, '.', '' ) . ' ' . $price->currency : '',
			$site_url . '/?p=' . $product->id,
			$image,
			$variant?->podProvider ?? $brand_def,
			$variant !== null ? (string) $product->id : '',
			$color,
			$size,
		];
	}

	/** @return Variant[] */
	private function variantsOf( Product $product ): array {
		$pdo = \Shop\DB::pdo();
		$stmt = $pdo->prepare( "SELECT id FROM product_variants WHERE product_id = :p AND enabled = 1 ORDER BY position ASC" );
		$stmt->execute( [ ':p' => $product->id ] );
		$out = [];
		foreach ( $stmt->fetchAll() as $r ) {
			$v = $this->products->findVariant( (int) $r['id'] );
			if ( $v !== null ) $out[] = $v;
		}
		return $out;
	}
}
