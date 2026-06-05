<?php
/**
 * Shop by Therum — TikTok Shop catalog CSV feed.
 *
 * Spec: https://seller-us.tiktok.com/university/article/100179
 *
 * Required columns:
 *   id, title, description, availability, condition, price, link,
 *   image_link, age_group, gender, google_product_category
 *
 * Like Meta + Google, TikTok wants normalized color/size — we route
 * through the dictionary.
 *
 * age_group + gender default to 'adult' / 'unisex'. Real classification
 * lands when we have product categories (post-v1).
 */

namespace Shop\Exporters;

use Shop\Models\Product;
use Shop\Models\Variant;
use Shop\Repositories\ProductRepository;
use Shop\Services\VendorDictionaryService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TikTokFeed implements Exporter {

	private const HEADERS = [
		'id', 'title', 'description', 'availability', 'condition',
		'price', 'link', 'image_link', 'brand', 'item_group_id',
		'color', 'size', 'age_group', 'gender', 'google_product_category',
	];

	public function __construct(
		private readonly CatalogReader $reader,
		private readonly ProductRepository $products,
		private readonly VendorDictionaryService $dictionary,
	) {}

	public function id(): string          { return 'tiktok-feed'; }
	public function displayName(): string { return 'TikTok Shop (CSV)'; }
	public function mimeType(): string    { return 'text/csv'; }
	public function extension(): string   { return 'csv'; }

	public function export( ExportQuery $query ): ExportResult {
		$site_url = $query->siteUrl ?? home_url();
		$brand    = (string) get_bloginfo( 'name' );

		$h = fopen( 'php://memory', 'r+' );
		fputcsv( $h, self::HEADERS );

		$count = 0;
		foreach ( $this->reader->walk( $query ) as $product ) {
			if ( $product->hasVariants ) {
				foreach ( $this->variantsOf( $product ) as $variant ) {
					fputcsv( $h, $this->row( $product, $variant, $site_url, $brand ) );
					$count++;
				}
			} else {
				fputcsv( $h, $this->row( $product, null, $site_url, $brand ) );
				$count++;
			}
		}

		rewind( $h );
		$body = (string) stream_get_contents( $h );
		fclose( $h );

		return ExportResult::string(
			body:     $body,
			filename: 'tiktok-' . gmdate( 'Y-m-d' ) . '.csv',
			mime:     $this->mimeType(),
			count:    $count,
		);
	}

	private function row( Product $product, ?Variant $variant, string $site_url, string $brand ): array {
		$id    = $variant?->sku ?? $product->sku ?? ( 'tp-' . $product->id . ( $variant ? '-v' . $variant->id : '' ) );
		$price = $variant?->price ?? $product->price;
		$image = $variant?->imageId
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
			$product->isPurchasable() ? 'in_stock' : 'out_of_stock',
			'new',
			$price !== null ? number_format( $price->minor / 100, 2, '.', '' ) . ' ' . $price->currency : '',
			$site_url . '/?p=' . $product->id,
			$image,
			$variant?->podProvider ?? $brand,
			$variant !== null ? (string) $product->id : '',
			$color,
			$size,
			'adult',
			'unisex',
			'',
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
