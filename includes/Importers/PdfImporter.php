<?php
/**
 * Shop by Therum — PDF importer (vision-model backed).
 *
 * Flow:
 *
 *   1. Open PDF via Imagick. (Ghostscript shell-out fallback planned
 *      when Imagick isn't compiled with PDF support.)
 *
 *   2. For each page (capped at MAX_PAGES per import to bound cost):
 *        - Render at 1500px wide JPEG
 *        - Send to Claude with extract_products tool
 *        - For each returned product:
 *          - If image_bbox set, crop the page JPEG to those coords and
 *            sideload as the product image
 *          - Build a PreviewProduct
 *
 *   3. Aggregate into ImportResult.
 *
 * Confidence comes back from the model. Anything < 0.6 gets the
 * "low confidence" review-grid styling in the admin UI.
 *
 * Cost discipline: vision calls are ~$0.01-0.03 per page. A 50-page
 * catalog runs ~$1. MAX_PAGES default 50 keeps any single import under
 * a dollar; admins can raise it via the `shop_pdf_max_pages` filter.
 */

namespace Shop\Importers;

use Shop\AI\ClaudeClient;
use Shop\AI\ProductExtractionTool;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PdfImporter implements Importer {

	private const MAX_PAGES_DEFAULT = 50;
	private const RENDER_WIDTH      = 1500;

	public function id(): string          { return 'pdf'; }
	public function displayName(): string { return 'PDF catalog (vision)'; }

	public function accepts( ImportSource $source ): bool {
		return $source->extension() === 'pdf' || $source->mimeType === 'application/pdf';
	}

	public function preview( ImportSource $source ): ImportResult {
		if ( ! ClaudeClient::isAvailable() ) {
			return new ImportResult(
				products:    [],
				summary:     'PDF import needs an Anthropic API key. Add `define( "SHOP_ANTHROPIC_API_KEY", "..." )` to wp-config.php to enable.',
				importerId:  $this->id(),
			);
		}

		if ( ! class_exists( \Imagick::class ) ) {
			return new ImportResult(
				products:    [],
				summary:     'PDF import requires the Imagick PHP extension to render pages.',
				importerId:  $this->id(),
				warnings:    [ 'Install php-imagick (most managed hosts have it).' ],
			);
		}

		$path = $source->filePath;
		if ( $path === null || ! is_file( $path ) ) {
			return new ImportResult(
				products:    [],
				summary:     'PDF source unreadable.',
				importerId:  $this->id(),
			);
		}

		$max_pages = (int) apply_filters( 'shop_pdf_max_pages', self::MAX_PAGES_DEFAULT );
		$client    = new ClaudeClient();
		$products  = [];
		$warnings  = [];

		try {
			$pdf = new \Imagick();
			$pdf->setResolution( 200, 200 );
			$pdf->readImage( $path );
			$page_count = min( $pdf->getNumberImages(), $max_pages );

			for ( $i = 0; $i < $page_count; $i++ ) {
				$pdf->setIteratorIndex( $i );
				$page = clone $pdf;
				$page->setImageFormat( 'jpeg' );
				$page->setImageCompressionQuality( 85 );
				$page->scaleImage( self::RENDER_WIDTH, 0 );

				$tmp = tempnam( sys_get_temp_dir(), 'shop-pdf-page-' );
				$page->writeImage( $tmp );

				try {
					$response = $client->complete(
						system: 'You are a careful product-catalog reader. Extract every product visible on this catalog page. Use the extract_products tool.',
						blocks: [
							ClaudeClient::imageBlockFromPath( $tmp, 'image/jpeg' ),
							[ 'type' => 'text', 'text' => sprintf( 'This is page %d of %d. List every product on this page.', $i + 1, $page_count ) ],
						],
						tools:  [ ProductExtractionTool::definition() ],
					);
					$tool_use = ClaudeClient::toolUseBlock( $response );
					if ( $tool_use !== null && is_array( $tool_use['input']['products'] ?? null ) ) {
						foreach ( $tool_use['input']['products'] as $raw ) {
							$products[] = $this->previewFromRaw( $raw, $i + 1, $tmp );
						}
					}
				} catch ( \Throwable $e ) {
					$warnings[] = sprintf( 'Page %d skipped: %s', $i + 1, $e->getMessage() );
				}

				@unlink( $tmp );
			}
			$pdf->clear();
		} catch ( \Throwable $e ) {
			return new ImportResult(
				products:    [],
				summary:     'PDF render failed: ' . $e->getMessage(),
				importerId:  $this->id(),
			);
		}

		$summary = sprintf(
			'Extracted %d product%s across %d page%s.',
			count( $products ), count( $products ) === 1 ? '' : 's',
			$page_count, $page_count === 1 ? '' : 's'
		);

		return new ImportResult(
			products:   $products,
			summary:    $summary,
			importerId: $this->id(),
			warnings:   $warnings,
		);
	}

	/**
	 * Build a PreviewProduct from the model's tool input. If the model
	 * returned an image_bbox, crop the rendered page to that region and
	 * sideload — the cropped image URL goes on imageUrls.
	 *
	 * @param array<string,mixed> $raw
	 */
	private function previewFromRaw( array $raw, int $pageNum, string $pageImagePath ): PreviewProduct {
		$title       = (string) ( $raw['title'] ?? '' );
		$description = $raw['description'] ?? null;
		$sku         = $raw['sku']         ?? null;
		$confidence  = (float) ( $raw['confidence'] ?? 0.7 );

		// Price preference: model's normalized cents → fallback to parsing price_text
		$price = null;
		if ( isset( $raw['price_cents'] ) && is_numeric( $raw['price_cents'] ) ) {
			try { $price = Money::cents( (int) $raw['price_cents'], 'USD' ); } catch ( \Throwable ) {}
		} elseif ( isset( $raw['price_text'] ) && is_string( $raw['price_text'] ) ) {
			$cents = $this->parsePriceText( $raw['price_text'] );
			if ( $cents !== null ) $price = Money::cents( $cents, 'USD' );
		}

		// Crop image if bbox provided
		$image_urls = [];
		$bbox = $raw['image_bbox'] ?? null;
		if ( is_array( $bbox ) && count( $bbox ) === 4 ) {
			$attachment_id = $this->cropAndSideload( $pageImagePath, $bbox, $title );
			if ( $attachment_id ) {
				$url = (string) wp_get_attachment_image_url( $attachment_id, 'large' );
				if ( $url ) $image_urls[] = $url;
			}
		}

		$attributes = array_filter( [
			'Color'    => $raw['color']    ?? null,
			'Size'     => $raw['size']     ?? null,
			'Material' => $raw['material'] ?? null,
		], fn( $v ): bool => is_string( $v ) && $v !== '' );

		$issues = [];
		if ( $price === null ) $issues[] = 'Price not detected.';
		if ( ! $image_urls )   $issues[] = 'No image extracted — page may not contain a discrete product image.';

		return new PreviewProduct(
			title:           $title,
			description:     is_string( $description ) ? $description : null,
			sku:             is_string( $sku ) ? $sku : null,
			price:           $price,
			compareAtPrice:  null,
			stockQty:        null,
			imageUrls:       $image_urls,
			attributes:      $attributes,
			variants:        [],
			confidence:      $confidence,
			sourceRef:       "page {$pageNum}",
			issues:          $issues,
		);
	}

	private function parsePriceText( string $text ): ?int {
		if ( ! preg_match( '/([0-9]{1,4}(?:[.,][0-9]{2})?)/', $text, $m ) ) return null;
		$num = str_replace( ',', '.', $m[1] );
		return (int) round( ( (float) $num ) * 100 );
	}

	/**
	 * Crop a region of the page JPEG and sideload as a media attachment.
	 *
	 * @param array{0:float,1:float,2:float,3:float} $bbox  [x, y, w, h] in 0..1
	 */
	private function cropAndSideload( string $pagePath, array $bbox, string $context ): ?int {
		try {
			$page = new \Imagick( $pagePath );
			$w = $page->getImageWidth();
			$h = $page->getImageHeight();
			$x = (int) round( $bbox[0] * $w );
			$y = (int) round( $bbox[1] * $h );
			$cw = (int) round( $bbox[2] * $w );
			$ch = (int) round( $bbox[3] * $h );
			$page->cropImage( max( 1, $cw ), max( 1, $ch ), max( 0, $x ), max( 0, $y ) );
			$page->setImagePage( 0, 0, 0, 0 );

			$crop_path = tempnam( sys_get_temp_dir(), 'shop-pdf-crop-' ) . '.jpg';
			$page->writeImage( $crop_path );
			$page->clear();

			$attachment = wp_handle_sideload(
				[ 'name' => basename( $crop_path ), 'tmp_name' => $crop_path ],
				[ 'test_form' => false ],
			);
			if ( ! empty( $attachment['error'] ) ) return null;

			$id = wp_insert_attachment( [
				'post_title'     => $context,
				'post_mime_type' => $attachment['type'],
			], $attachment['file'] );
			if ( is_wp_error( $id ) ) return null;

			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $attachment['file'] ) );
			return $id > 0 ? $id : null;
		} catch ( \Throwable ) {
			return null;
		}
	}
}
