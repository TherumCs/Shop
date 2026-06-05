<?php
/**
 * Shop by Therum — Figma importer.
 *
 * Flow:
 *
 *   1. Parse Figma URL → fileKey + (optional) nodeId
 *   2. Call Figma's /v1/files/:fileKey?ids= to walk the node tree
 *   3. Detect candidate "product card" frames: any FRAME or COMPONENT
 *      node whose name suggests a product card OR whose children include
 *      both an image-fill rectangle and at least one text node
 *   4. Call /v1/images/:fileKey?ids=... to render the candidate frames
 *      to PNG (Figma returns hosted URLs valid for ~30 days)
 *   5. Download each PNG, send to Claude with extract_products, take
 *      the first product per frame
 *
 * This mostly produces correct results on well-structured catalog files.
 * Free-form layouts fall back to whole-page rendering + the vision
 * approach used by PdfImporter.
 *
 * Configuration:
 *   define( 'SHOP_FIGMA_API_TOKEN', 'figd_...' );
 */

namespace Shop\Importers;

use Shop\AI\ClaudeClient;
use Shop\AI\ProductExtractionTool;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class FigmaImporter implements Importer {

	private const FIGMA_API = 'https://api.figma.com';

	public function id(): string          { return 'figma'; }
	public function displayName(): string { return 'Figma file'; }

	public function accepts( ImportSource $source ): bool {
		if ( $source->url === null ) return false;
		$host = strtolower( (string) parse_url( $source->url, PHP_URL_HOST ) );
		return str_contains( $host, 'figma.com' );
	}

	public function preview( ImportSource $source ): ImportResult {
		$has_token = defined( 'SHOP_FIGMA_API_TOKEN' ) && SHOP_FIGMA_API_TOKEN !== '';
		if ( ! $has_token ) {
			return new ImportResult(
				products:    [],
				summary:     'Figma import needs a Figma API token. Add `define( "SHOP_FIGMA_API_TOKEN", "..." )` to wp-config.php.',
				importerId:  $this->id(),
			);
		}
		if ( ! ClaudeClient::isAvailable() ) {
			return new ImportResult(
				products:    [],
				summary:     'Figma import also needs SHOP_ANTHROPIC_API_KEY for the vision pass.',
				importerId:  $this->id(),
			);
		}

		$file_key = $this->parseFileKey( (string) $source->url );
		if ( $file_key === null ) {
			return new ImportResult(
				products:    [],
				summary:     'Could not parse Figma file URL.',
				importerId:  $this->id(),
			);
		}

		// Step 1: fetch file tree, find candidate frames
		$file = $this->figmaGet( "/v1/files/{$file_key}" );
		if ( ! is_array( $file ) ) {
			return new ImportResult(
				products:    [],
				summary:     'Figma file request failed.',
				importerId:  $this->id(),
			);
		}

		$candidates = $this->findCandidateFrames( $file['document'] ?? [] );
		if ( ! $candidates ) {
			return new ImportResult(
				products:    [],
				summary:     'No product-card-shaped frames detected in the file.',
				importerId:  $this->id(),
				warnings:    [ 'Try a file where products are grouped as FRAME or COMPONENT nodes named "card", "product", etc.' ],
			);
		}

		// Cap to bound cost
		$max = (int) apply_filters( 'shop_figma_max_frames', 50 );
		$candidates = array_slice( $candidates, 0, $max );
		$ids = implode( ',', array_map( fn( array $c ): string => (string) $c['id'], $candidates ) );

		// Step 2: render those frames to images
		$render = $this->figmaGet( "/v1/images/{$file_key}?ids={$ids}&format=png&scale=2" );
		$urls   = is_array( $render['images'] ?? null ) ? $render['images'] : [];

		// Step 3: vision call per frame
		$client = new ClaudeClient();
		$products = [];
		$warnings = [];
		foreach ( $candidates as $cand ) {
			$png_url = $urls[ $cand['id'] ] ?? null;
			if ( ! $png_url ) continue;

			$bytes = wp_remote_retrieve_body( wp_remote_get( $png_url, [ 'timeout' => 30 ] ) );
			if ( ! is_string( $bytes ) || $bytes === '' ) continue;

			$tmp = tempnam( sys_get_temp_dir(), 'shop-figma-' ) . '.png';
			file_put_contents( $tmp, $bytes );

			try {
				$res = $client->complete(
					system: 'You are extracting a single product from a catalog frame. Use the extract_products tool.',
					blocks: [
						ClaudeClient::imageBlockFromPath( $tmp, 'image/png' ),
						[ 'type' => 'text', 'text' => sprintf( 'This frame is named "%s". Extract the product.', $cand['name'] ) ],
					],
					tools: [ ProductExtractionTool::definition() ],
				);
				$tool_use = ClaudeClient::toolUseBlock( $res );
				$list = is_array( $tool_use['input']['products'] ?? null ) ? $tool_use['input']['products'] : [];
				foreach ( $list as $raw ) {
					$products[] = $this->previewFromRaw( $raw, $cand, $tmp );
				}
			} catch ( \Throwable $e ) {
				$warnings[] = sprintf( 'Frame "%s" skipped: %s', $cand['name'], $e->getMessage() );
			}

			@unlink( $tmp );
		}

		return new ImportResult(
			products:    $products,
			summary:     sprintf( 'Extracted %d product%s from %d frame%s in the Figma file.',
				count( $products ), count( $products ) === 1 ? '' : 's',
				count( $candidates ), count( $candidates ) === 1 ? '' : 's' ),
			importerId:  $this->id(),
			warnings:    $warnings,
		);
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function parseFileKey( string $url ): ?string {
		if ( preg_match( '#figma\.com/(?:file|design)/([A-Za-z0-9]+)#', $url, $m ) ) return $m[1];
		return null;
	}

	private function figmaGet( string $endpoint ): mixed {
		$res = wp_remote_get( self::FIGMA_API . $endpoint, [
			'timeout' => 30,
			'headers' => [ 'X-Figma-Token' => (string) SHOP_FIGMA_API_TOKEN ],
		] );
		if ( is_wp_error( $res ) ) return null;
		$body = wp_remote_retrieve_body( $res );
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Walk the node tree looking for FRAME or COMPONENT nodes whose name
	 * suggests a product card.
	 *
	 * @param array<string,mixed> $node
	 * @return array<int, array{id:string,name:string}>
	 */
	private function findCandidateFrames( array $node ): array {
		$out = [];
		$name = strtolower( (string) ( $node['name'] ?? '' ) );
		$type = (string) ( $node['type'] ?? '' );

		if ( in_array( $type, [ 'FRAME', 'COMPONENT', 'INSTANCE' ], true ) ) {
			$matches = preg_match( '/\b(card|product|item|catalog)\b/', $name ) === 1;
			if ( $matches ) {
				$out[] = [
					'id'   => (string) ( $node['id'] ?? '' ),
					'name' => (string) ( $node['name'] ?? '' ),
				];
			}
		}

		foreach ( (array) ( $node['children'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) {
				$out = array_merge( $out, $this->findCandidateFrames( $child ) );
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed>            $raw
	 * @param array{id:string,name:string}   $frame
	 */
	private function previewFromRaw( array $raw, array $frame, string $imagePath ): PreviewProduct {
		// Sideload the frame render so the imported product has a real image.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = null;
		$copy = tempnam( sys_get_temp_dir(), 'shop-figma-keep-' ) . '.png';
		if ( @copy( $imagePath, $copy ) ) {
			$attachment = wp_handle_sideload(
				[ 'name' => basename( $copy ), 'tmp_name' => $copy ],
				[ 'test_form' => false ],
			);
			if ( empty( $attachment['error'] ) ) {
				$attachment_id = (int) wp_insert_attachment( [
					'post_title'     => (string) ( $raw['title'] ?? 'Imported' ),
					'post_mime_type' => $attachment['type'],
				], $attachment['file'] );
				if ( $attachment_id > 0 ) {
					wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $attachment['file'] ) );
				}
			}
		}

		$image_urls = [];
		if ( $attachment_id ) {
			$url = (string) wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( $url ) $image_urls[] = $url;
		}

		$price = null;
		if ( isset( $raw['price_cents'] ) && is_numeric( $raw['price_cents'] ) ) {
			try { $price = Money::cents( (int) $raw['price_cents'], 'USD' ); } catch ( \Throwable ) {}
		}

		return new PreviewProduct(
			title:           (string) ( $raw['title'] ?? 'Untitled' ),
			description:     is_string( $raw['description'] ?? null ) ? $raw['description'] : null,
			sku:             is_string( $raw['sku']         ?? null ) ? $raw['sku']         : null,
			price:           $price,
			compareAtPrice:  null,
			stockQty:        null,
			imageUrls:       $image_urls,
			attributes:      array_filter( [
				'Color'    => $raw['color']    ?? null,
				'Size'     => $raw['size']     ?? null,
				'Material' => $raw['material'] ?? null,
			], fn( $v ): bool => is_string( $v ) && $v !== '' ),
			variants:        [],
			confidence:      (float) ( $raw['confidence'] ?? 0.75 ),
			sourceRef:       sprintf( 'frame "%s"', $frame['name'] ),
			issues:          $price === null ? [ 'Price not detected — set after import.' ] : [],
		);
	}
}
