<?php
/**
 * Shop by Therum — URL importer.
 *
 * Fetches a public URL and extracts products. Two strategies, tried in
 * order:
 *
 *   1. JSON-LD Product schema embedded in the page. Shopify, Squarespace,
 *      BigCommerce, modern Woo, and most Webflow stores publish this.
 *      We pull every <script type="application/ld+json"> block, look for
 *      `@type: Product`, and translate to PreviewProduct. Highest fidelity.
 *
 *   2. Open Graph metadata (og:title, og:description, og:image,
 *      og:product:price:amount). Lower fidelity but works on most pages
 *      that mention a product. Fallback.
 *
 * Use cases:
 *   - Paste a Shopify product URL, import that product to Shop.
 *   - Paste a competitor's page, mirror it (review carefully — pricing,
 *     descriptions, images may be IP).
 *   - Paste a Webflow page you built as a static catalog, import to Shop.
 *
 * Multi-product URLs (a category page) emit one PreviewProduct per
 * detected Product schema block. The whole collection comes through.
 */

namespace Shop\Importers;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class UrlImporter implements Importer {

	public function id(): string          { return 'url'; }
	public function displayName(): string { return 'URL (JSON-LD / OG)'; }

	public function accepts( ImportSource $source ): bool {
		if ( $source->url === null ) return false;
		$scheme = parse_url( $source->url, PHP_URL_SCHEME );
		return in_array( strtolower( (string) $scheme ), [ 'http', 'https' ], true );
	}

	public function preview( ImportSource $source ): ImportResult {
		$html = $source->read();
		if ( $html === '' ) {
			return new ImportResult( [], 'Could not fetch URL', $this->id() );
		}

		// Strategy 1 — JSON-LD
		$products = $this->extractJsonLd( $html, (string) $source->url );
		if ( $products ) {
			$summary = sprintf(
				'Found %d product%s in JSON-LD from %s.',
				count( $products ),
				count( $products ) === 1 ? '' : 's',
				$source->url
			);
			return new ImportResult( $products, $summary, $this->id() );
		}

		// Strategy 2 — Open Graph
		$og_product = $this->extractOpenGraph( $html, (string) $source->url );
		if ( $og_product !== null ) {
			return new ImportResult(
				[ $og_product ],
				sprintf( 'Found 1 product via Open Graph from %s.', $source->url ),
				$this->id(),
				[ 'Open Graph data is limited — variant/size detection unavailable. Review carefully.' ]
			);
		}

		return new ImportResult( [], 'No product schema or Open Graph data found at that URL.', $this->id() );
	}

	/** @return PreviewProduct[] */
	private function extractJsonLd( string $html, string $url ): array {
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks ) ) {
			return [];
		}

		$products = [];
		foreach ( $blocks[1] as $raw ) {
			$decoded = json_decode( trim( $raw ), true );
			if ( ! is_array( $decoded ) ) continue;
			// JSON-LD blocks can be a single object, an array, or a @graph wrapper.
			$candidates = isset( $decoded['@graph'] ) ? $decoded['@graph']
				: ( isset( $decoded[0] ) ? $decoded : [ $decoded ] );

			foreach ( $candidates as $node ) {
				if ( ! is_array( $node ) ) continue;
				$type = $node['@type'] ?? '';
				$type = is_array( $type ) ? $type[0] : $type;
				if ( $type !== 'Product' ) continue;
				$products[] = $this->productFromLd( $node, $url );
			}
		}
		return $products;
	}

	private function productFromLd( array $node, string $url ): PreviewProduct {
		$title = (string) ( $node['name'] ?? '' );
		$desc  = (string) ( $node['description'] ?? '' );
		$sku   = $node['sku'] ?? $node['mpn'] ?? null;
		$sku   = is_string( $sku ) ? $sku : null;

		// Images can be string or array of strings or array of ImageObject.
		$images = [];
		$img = $node['image'] ?? null;
		if ( is_string( $img ) )    $images[] = $img;
		if ( is_array( $img ) ) {
			foreach ( $img as $candidate ) {
				if ( is_string( $candidate ) )                    $images[] = $candidate;
				if ( is_array( $candidate ) && isset( $candidate['url'] ) ) $images[] = (string) $candidate['url'];
			}
		}

		$offer = $node['offers'] ?? null;
		if ( is_array( $offer ) && isset( $offer[0] ) ) $offer = $offer[0]; // AggregateOffer
		$price = null;
		if ( is_array( $offer ) ) {
			$p = $offer['price'] ?? ( $offer['lowPrice'] ?? null );
			if ( is_numeric( $p ) ) {
				try {
					$price = Money::fromMajor( (string) $p, strtoupper( (string) ( $offer['priceCurrency'] ?? 'USD' ) ) );
				} catch ( \Throwable ) {}
			}
		}

		$issues = [];
		if ( $price === null ) $issues[] = 'No price in offer.';
		if ( ! $images )       $issues[] = 'No image URL in schema.';

		return new PreviewProduct(
			title:           $title,
			description:     $desc !== '' ? $desc : null,
			sku:             $sku,
			price:           $price,
			compareAtPrice:  null,
			stockQty:        null,
			imageUrls:       $images,
			attributes:      [],
			variants:        [],
			confidence:      0.85,
			sourceRef:       $url,
			issues:          $issues,
		);
	}

	private function extractOpenGraph( string $html, string $url ): ?PreviewProduct {
		$tag = function ( string $property ) use ( $html ): ?string {
			if ( preg_match(
				'#<meta\s+(?:[^>]*?\s+)?property=["\']' . preg_quote( $property, '#' ) . '["\']\s+content=["\']([^"\']+)["\']#i',
				$html, $m
			) ) {
				return html_entity_decode( $m[1] );
			}
			return null;
		};

		$title = $tag( 'og:title' );
		$desc  = $tag( 'og:description' );
		$img   = $tag( 'og:image' );
		$pamt  = $tag( 'og:product:price:amount' )
			?? $tag( 'product:price:amount' );
		$pcur  = $tag( 'og:product:price:currency' )
			?? $tag( 'product:price:currency' )
			?? 'USD';

		if ( $title === null ) return null;

		$price = null;
		if ( $pamt !== null && is_numeric( $pamt ) ) {
			try { $price = Money::fromMajor( $pamt, strtoupper( $pcur ) ); } catch ( \Throwable ) {}
		}

		$issues = [];
		if ( $price === null ) $issues[] = 'No price in Open Graph.';
		if ( $img === null )   $issues[] = 'No og:image.';

		return new PreviewProduct(
			title:           $title,
			description:     $desc,
			sku:             null,
			price:           $price,
			compareAtPrice:  null,
			stockQty:        null,
			imageUrls:       $img !== null ? [ $img ] : [],
			attributes:      [],
			variants:        [],
			confidence:      0.6,
			sourceRef:       $url,
			issues:          $issues,
		);
	}
}
