<?php
/**
 * Counter by Therum — REST: importer endpoints.
 *
 * Routes (namespace shop/v1):
 *
 *   POST /counter/v1/import/preview  — upload file OR send URL, returns
 *                                   preview products (no DB writes)
 *   POST /counter/v1/import/commit   — confirm a preview, writes to SQLite
 *   GET  /counter/v1/import/options  — list registered importers
 *
 * Auth: capability `manage_woocommerce` (or `manage_options` fallback)
 * — only admins can import.
 *
 * The preview flow holds nothing on the server between preview and
 * commit; the client round-trips the full preview payload back on
 * commit. Stateless. If a customer leaves the review screen for an hour,
 * nothing has to time out — they re-submit and we re-insert.
 */

namespace Counter\Rest;

use Counter\Importers\ImportSource;
use Counter\Importers\PreviewProduct;
use Counter\Importers\PreviewVariant;
use Counter\Money;
use Counter\Services\ImporterRegistry;
use Counter\Services\ProductWriter;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImporterController {

	public const NAMESPACE = 'counter/v1';

	public function __construct(
		private readonly ImporterRegistry $registry,
		private readonly ProductWriter $writer,
	) {}

	public function register(): void {
		$auth = function (): bool {
			return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		};

		register_rest_route( self::NAMESPACE, '/import/options', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'options' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/import/preview', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'preview' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/import/commit', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'commit' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/import/woocommerce', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'importWooCommerce' ],
			'permission_callback' => $auth,
		] );
	}

	public function options( \WP_REST_Request $req ): \WP_REST_Response {
		$opts = array_map( fn( $i ): array => [
			'id'    => $i->id(),
			'label' => $i->displayName(),
		], $this->registry->all() );
		return new \WP_REST_Response( [ 'importers' => $opts ], 200 );
	}

	public function preview( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$source = $this->sourceFromRequest( $req );
		} catch ( \DomainException $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 400 );
		}

		// Caller may pin an importer; otherwise auto-route.
		$importer_id = (string) ( $req->get_param( 'importer' ) ?? '' );
		try {
			$importer = $importer_id !== ''
				? $this->registry->pick( $importer_id )
				: $this->registry->route( $source );
		} catch ( \DomainException $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 400 );
		}

		if ( $importer === null ) {
			return new \WP_REST_Response( [
				'error' => [ 'message' => 'No importer accepts that source. Pin one explicitly via `importer` param.' ],
			], 422 );
		}

		try {
			$result = $importer->preview( $source );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [
				'error' => [ 'message' => 'Importer crashed: ' . $e->getMessage() ],
			], 500 );
		}

		return new \WP_REST_Response( $result->toJson(), 200 );
	}

	public function commit( \WP_REST_Request $req ): \WP_REST_Response {
		$products_json = (array) $req->get_param( 'products' );
		if ( ! $products_json ) {
			return new \WP_REST_Response( [
				'error' => [ 'message' => 'No products in payload.' ],
			], 400 );
		}

		$previews = array_map( [ $this, 'previewFromJson' ], $products_json );
		$ids      = $this->writer->bulk( $previews );

		return new \WP_REST_Response( [
			'count'    => count( array_filter( $ids ) ),
			'failures' => count( array_filter( $ids, fn( int $id ): bool => $id === 0 ) ),
			'ids'      => $ids,
		], 200 );
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	private function sourceFromRequest( \WP_REST_Request $req ): ImportSource {
		$url  = (string) $req->get_param( 'url' );
		$text = (string) $req->get_param( 'text' );
		$mime = (string) $req->get_param( 'mime' );

		// Uploaded file via multipart/form-data
		$files = $req->get_file_params();
		if ( isset( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			return ImportSource::file(
				path: $files['file']['tmp_name'],
				mime: (string) ( $files['file']['type'] ?? $mime ),
			);
		}

		if ( $url !== '' ) {
			return ImportSource::url( esc_url_raw( $url ) );
		}

		if ( $text !== '' ) {
			return ImportSource::text( $text, $mime !== '' ? $mime : 'text/plain' );
		}

		throw new \DomainException( 'Provide file, url, or text.' );
	}

	/**
	 * Rebuild a PreviewProduct DTO from a JSON shape (round-tripped from
	 * the preview response).
	 *
	 * @param array<string,mixed> $j
	 */
	private function previewFromJson( array $j ): PreviewProduct {
		$money = function ( $minor, string $currency = 'USD' ): ?Money {
			return is_numeric( $minor ) ? Money::cents( (int) $minor, $currency ) : null;
		};

		$variants = array_map( function ( array $v ) use ( $money ): PreviewVariant {
			return new PreviewVariant(
				sku:             $v['sku']             ?? null,
				options:         (array) ( $v['options'] ?? [] ),
				price:           $money( $v['price'] ?? null ),
				compareAtPrice:  $money( $v['compare_at_price'] ?? null ),
				stockQty:        isset( $v['stock_qty'] ) ? (int) $v['stock_qty'] : null,
				imageUrl:        $v['image_url']        ?? null,
				podProvider:     $v['pod_provider']     ?? null,
				podProductId:    $v['pod_product_id']   ?? null,
				podVariantId:    $v['pod_variant_id']   ?? null,
			);
		}, (array) ( $j['variants'] ?? [] ) );

		return new PreviewProduct(
			title:           (string) ( $j['title'] ?? '' ),
			description:     $j['description'] ?? null,
			sku:             $j['sku']         ?? null,
			price:           $money( $j['price'] ?? null ),
			compareAtPrice:  $money( $j['compare_at_price'] ?? null ),
			stockQty:        isset( $j['stock_qty'] ) ? (int) $j['stock_qty'] : null,
			imageUrls:       (array) ( $j['image_urls'] ?? [] ),
			attributes:      (array) ( $j['attributes'] ?? [] ),
			variants:        $variants,
			confidence:      (float) ( $j['confidence'] ?? 1.0 ),
			sourceRef:       (string) ( $j['source_ref'] ?? '' ),
			issues:          (array) ( $j['issues'] ?? [] ),
		);
	}

	/**
	 * Import everything from WooCommerce: products, customers, orders.
	 *
	 * One-click comprehensive import. After this, you can safely delete WooCommerce.
	 */
	public function importWooCommerce( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new \WP_REST_Response( [
				'code'    => 'woocommerce_not_active',
				'message' => 'WooCommerce is not installed or active',
			], 400 );
		}

		try {
			$importer = new \Counter\Services\ComprehensiveWooImporter();
			$result = $importer->importEverything();

			if ( $result['success'] ) {
				return new \WP_REST_Response( $result, 200 );
			} else {
				return new \WP_REST_Response( [
					'code'    => 'import_failed',
					'message' => $result['message'],
				], 422 );
			}
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [
				'code'    => 'import_error',
				'message' => $e->getMessage(),
			], 500 );
		}
	}
}
