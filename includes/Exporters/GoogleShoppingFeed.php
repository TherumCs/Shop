<?php
/**
 * Shop by Therum — Google Shopping XML feed.
 *
 * Spec: https://support.google.com/merchants/answer/7052112
 *
 * Output is an RSS 2.0 channel with `g:`-namespaced item elements.
 * Google Merchant Center polls this on a schedule.
 *
 * Vendor-aware normalization via VendorDictionaryService — Printful's
 * "Ocean" becomes Google-compliant "Blue" before emit. The dictionary
 * does the heavy lifting; the feed just calls translate().
 *
 * One <item> per variant for variable products (Google wants the SKU
 * resolution, not the parent). Identical parent fields repeat across
 * variants except for the variant-specific bits (color, size, price,
 * image).
 */

namespace Shop\Exporters;

use Shop\Models\Product;
use Shop\Models\Variant;
use Shop\Repositories\ProductRepository;
use Shop\Services\VendorDictionaryService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GoogleShoppingFeed implements Exporter {

	public function __construct(
		private readonly CatalogReader $reader,
		private readonly ProductRepository $products,
		private readonly VendorDictionaryService $dictionary,
	) {}

	public function id(): string          { return 'google-shopping'; }
	public function displayName(): string { return 'Google Shopping (XML)'; }
	public function mimeType(): string    { return 'application/xml; charset=utf-8'; }
	public function extension(): string   { return 'xml'; }

	public function export( ExportQuery $query ): ExportResult {
		$site_url   = $query->siteUrl ?? home_url();
		$site_name  = (string) get_bloginfo( 'name' );
		$site_desc  = (string) get_bloginfo( 'description' );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n";
		$xml .= '<channel>' . "\n";
		$xml .= '<title>'       . $this->cdata( $site_name ) . '</title>' . "\n";
		$xml .= '<link>'        . esc_url( $site_url )       . '</link>' . "\n";
		$xml .= '<description>' . $this->cdata( $site_desc ) . '</description>' . "\n";

		$count = 0;
		foreach ( $this->reader->walk( $query ) as $product ) {
			if ( $product->hasVariants ) {
				foreach ( $this->variantsOf( $product ) as $variant ) {
					$xml .= $this->itemXml( $product, $variant, $site_url );
					$count++;
				}
			} else {
				$xml .= $this->itemXml( $product, null, $site_url );
				$count++;
			}
		}

		$xml .= '</channel></rss>' . "\n";

		return ExportResult::string(
			body:     $xml,
			filename: 'google-shopping-' . gmdate( 'Y-m-d' ) . '.xml',
			mime:     $this->mimeType(),
			count:    $count,
		);
	}

	private function itemXml( Product $product, ?Variant $variant, string $site_url ): string {
		$id    = $variant?->sku ?? $product->sku ?? ( 'tp-' . $product->id . ( $variant ? '-v' . $variant->id : '' ) );
		$title = esc_html( $product->title );
		$desc  = esc_html( wp_strip_all_tags( $product->description ?? $product->shortDescription ?? '' ) );
		$link  = esc_url( $site_url . '/?p=' . $product->id ); // TODO: pretty permalinks lands when product page does
		$image = $variant?->imageId
			? wp_get_attachment_image_url( $variant->imageId, 'large' )
			: ( $product->primaryImageId ? wp_get_attachment_image_url( $product->primaryImageId, 'large' ) : '' );
		$price = $variant?->price ?? $product->price;
		$compare = $variant?->compareAtPrice ?? $product->compareAtPrice;

		// Vendor-normalized color / size via dictionary
		$color = '';
		$size  = '';
		if ( $variant !== null ) {
			$opts = $variant->meta['options'] ?? [];
			if ( isset( $opts['Color'] ) || isset( $opts['color'] ) ) {
				$raw = (string) ( $opts['Color'] ?? $opts['color'] );
				$color = $variant->podProvider
					? ( $this->dictionary->translate( $variant->podProvider, 'color', $raw ) ?? $raw )
					: $raw;
			}
			if ( isset( $opts['Size'] ) || isset( $opts['size'] ) ) {
				$raw = (string) ( $opts['Size'] ?? $opts['size'] );
				$size = $variant->podProvider
					? ( $this->dictionary->translate( $variant->podProvider, 'size', $raw ) ?? $raw )
					: $raw;
			}
		}

		$availability = $product->isPurchasable()
			&& ( $variant === null || $variant->stockQty === null || $variant->stockQty > 0 )
			? 'in_stock' : 'out_of_stock';

		$out  = "  <item>\n";
		$out .= '    <g:id>'           . esc_html( $id )    . "</g:id>\n";
		$out .= '    <g:title>'        . $title             . "</g:title>\n";
		$out .= '    <g:description>'  . $desc              . "</g:description>\n";
		$out .= '    <g:link>'         . $link              . "</g:link>\n";
		if ( $image ) {
			$out .= '    <g:image_link>'   . esc_url( $image ) . "</g:image_link>\n";
		}
		$out .= '    <g:availability>' . $availability      . "</g:availability>\n";
		if ( $price !== null ) {
			$out .= '    <g:price>' . number_format( $price->minor / 100, 2, '.', '' ) . ' ' . esc_html( $price->currency ) . "</g:price>\n";
		}
		if ( $compare !== null && $price !== null && $compare->greaterThan( $price ) ) {
			$out .= '    <g:sale_price>' . number_format( $price->minor / 100, 2, '.', '' ) . ' ' . esc_html( $price->currency ) . "</g:sale_price>\n";
		}
		$out .= '    <g:condition>new</g:condition>' . "\n";
		$out .= '    <g:brand>' . esc_html( $variant?->podProvider ?? get_bloginfo( 'name' ) ) . "</g:brand>\n";
		if ( $color !== '' ) $out .= '    <g:color>' . esc_html( $color ) . "</g:color>\n";
		if ( $size  !== '' ) $out .= '    <g:size>'  . esc_html( $size )  . "</g:size>\n";
		if ( $variant !== null ) {
			$out .= '    <g:item_group_id>' . esc_html( (string) $product->id ) . "</g:item_group_id>\n";
		}
		$out .= "  </item>\n";
		return $out;
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

	private function cdata( string $s ): string {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $s ) . ']]>';
	}
}
