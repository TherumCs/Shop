<?php
/**
 * Shop by Therum — Single-image importer (vision-model backed).
 *
 * Flow:
 *
 *   1. Sideload the image to the WP media library so we own a local
 *      copy that the resulting PreviewProduct can reference.
 *   2. Send the image to Claude with the extract_products tool.
 *   3. Use the first product the model finds (typically only one for
 *      a product-card-shaped image).
 *   4. Use the sideloaded URL as the imageUrls — no crop needed for
 *      single-image imports.
 *
 * Best for: one-off product additions where clicking through admin
 * forms is annoying. Drag a photo of a product into the importer,
 * confirm the extracted details, save.
 */

namespace Shop\Importers;

use Shop\AI\ClaudeClient;
use Shop\AI\ProductExtractionTool;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImageImporter implements Importer {

	public function id(): string          { return 'image'; }
	public function displayName(): string { return 'Single image (vision)'; }

	public function accepts( ImportSource $source ): bool {
		return in_array( $source->extension(), [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ], true )
			|| ( $source->mimeType !== null && str_starts_with( $source->mimeType, 'image/' ) );
	}

	public function preview( ImportSource $source ): ImportResult {
		if ( ! ClaudeClient::isAvailable() ) {
			return new ImportResult(
				products:    [],
				summary:     'Image import needs an Anthropic API key. Add `define( "SHOP_ANTHROPIC_API_KEY", "..." )` to wp-config.php.',
				importerId:  $this->id(),
			);
		}

		$path = $source->filePath;
		if ( $path === null || ! is_file( $path ) ) {
			return new ImportResult(
				products:    [],
				summary:     'Image source unreadable.',
				importerId:  $this->id(),
			);
		}

		$mime = $source->mimeType ?? mime_content_type( $path ) ?: 'image/jpeg';

		try {
			$client = new ClaudeClient();
			$response = $client->complete(
				system: 'You are extracting product data from a single product image. Identify exactly one product and return its details via the extract_products tool. Be precise — do not invent details.',
				blocks: [
					ClaudeClient::imageBlockFromPath( $path, $mime ),
					[ 'type' => 'text', 'text' => 'Identify the product in this image. Use the extract_products tool.' ],
				],
				tools: [ ProductExtractionTool::definition() ],
			);
		} catch ( \Throwable $e ) {
			return new ImportResult(
				products:    [],
				summary:     'Vision call failed: ' . $e->getMessage(),
				importerId:  $this->id(),
			);
		}

		$tool_use = ClaudeClient::toolUseBlock( $response );
		$raw_list = is_array( $tool_use['input']['products'] ?? null )
			? $tool_use['input']['products']
			: [];

		if ( ! $raw_list ) {
			return new ImportResult(
				products:    [],
				summary:     'No product identified in image.',
				importerId:  $this->id(),
			);
		}

		// Sideload the source image so the PreviewProduct has a stable URL.
		$attachment_id = $this->sideloadOriginal( $path, (string) ( $raw_list[0]['title'] ?? 'Imported product' ) );
		$image_urls    = [];
		if ( $attachment_id !== null ) {
			$url = (string) wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( $url ) $image_urls[] = $url;
		}

		$raw = $raw_list[0];

		$price = null;
		if ( isset( $raw['price_cents'] ) && is_numeric( $raw['price_cents'] ) ) {
			try { $price = Money::cents( (int) $raw['price_cents'], 'USD' ); } catch ( \Throwable ) {}
		}

		$attributes = array_filter( [
			'Color'    => $raw['color']    ?? null,
			'Size'     => $raw['size']     ?? null,
			'Material' => $raw['material'] ?? null,
		], fn( $v ): bool => is_string( $v ) && $v !== '' );

		$issues = [];
		if ( $price === null ) $issues[] = 'No price visible — set one after import.';

		$preview = new PreviewProduct(
			title:           (string) ( $raw['title'] ?? 'Untitled' ),
			description:     is_string( $raw['description'] ?? null ) ? $raw['description'] : null,
			sku:             is_string( $raw['sku']         ?? null ) ? $raw['sku']         : null,
			price:           $price,
			compareAtPrice:  null,
			stockQty:        null,
			imageUrls:       $image_urls,
			attributes:      $attributes,
			variants:        [],
			confidence:      (float) ( $raw['confidence'] ?? 0.8 ),
			sourceRef:       $source->filename ?? 'uploaded image',
			issues:          $issues,
		);

		return new ImportResult(
			products:    [ $preview ],
			summary:     '1 product identified.',
			importerId:  $this->id(),
		);
	}

	private function sideloadOriginal( string $path, string $context ): ?int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = tempnam( sys_get_temp_dir(), 'shop-img-' ) . '.' . pathinfo( $path, PATHINFO_EXTENSION );
		if ( ! @copy( $path, $tmp ) ) return null;

		$attachment = wp_handle_sideload(
			[ 'name' => basename( $tmp ), 'tmp_name' => $tmp ],
			[ 'test_form' => false ],
		);
		if ( ! empty( $attachment['error'] ) ) return null;

		$id = wp_insert_attachment( [
			'post_title'     => $context,
			'post_mime_type' => $attachment['type'],
		], $attachment['file'] );
		if ( is_wp_error( $id ) ) return null;

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $attachment['file'] ) );
		return $id > 0 ? (int) $id : null;
	}
}
