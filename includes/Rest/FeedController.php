<?php
/**
 * Shop by Therum — REST: feed + export endpoints.
 *
 * Public feed routes (no auth — these are crawled by Google / Meta / TikTok):
 *
 *   GET /shop/v1/feeds/google-shopping.xml
 *   GET /shop/v1/feeds/meta-catalog.csv
 *   GET /shop/v1/feeds/tiktok-feed.csv
 *
 * Cached for 15 minutes via WP transient. Feed providers don't need
 * second-by-second freshness; the catalog moves once a day for most stores.
 *
 * Admin export routes (auth gated):
 *
 *   POST /shop/v1/admin/export     — { format, status?, podProvider?, ids? }
 *   GET  /shop/v1/admin/exporters  — list available formats
 */

namespace Shop\Rest;

use Shop\Exporters\ExportQuery;
use Shop\Services\ExporterRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class FeedController {

	public const NAMESPACE = 'shop/v1';
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	public function __construct(
		private readonly ExporterRegistry $exporters,
	) {}

	public function register(): void {
		register_rest_route( self::NAMESPACE, '/feeds/(?P<id>[a-z0-9-]+)\.(?P<ext>xml|csv|json)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'serveFeed' ],
			'permission_callback' => '__return_true',
		] );

		$auth = function (): bool {
			return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		};

		register_rest_route( self::NAMESPACE, '/admin/exporters', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'listExporters' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/export', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'export' ],
			'permission_callback' => $auth,
		] );
	}

	public function serveFeed( \WP_REST_Request $req ): \WP_REST_Response {
		$id  = (string) $req->get_param( 'id' );
		$ext = (string) $req->get_param( 'ext' );

		if ( ! $this->exporters->has( $id ) ) {
			return new \WP_REST_Response( [ 'error' => 'unknown feed' ], 404 );
		}

		$exporter = $this->exporters->get( $id );
		if ( $exporter->extension() !== $ext ) {
			return new \WP_REST_Response( [ 'error' => 'extension mismatch' ], 400 );
		}

		$cache_key = 'shop_feed_' . $id;
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $this->raw( $cached, $exporter->mimeType() );
		}

		$result = $exporter->export( new ExportQuery(
			status:  'active',
			siteUrl: home_url(),
		) );

		$body = $result->isFile ? (string) @file_get_contents( $result->body ) : $result->body;
		set_transient( $cache_key, $body, self::CACHE_TTL );
		return $this->raw( $body, $exporter->mimeType() );
	}

	public function listExporters( \WP_REST_Request $req ): \WP_REST_Response {
		$list = array_map( fn( $e ): array => [
			'id'        => $e->id(),
			'label'     => $e->displayName(),
			'mime'      => $e->mimeType(),
			'extension' => $e->extension(),
		], $this->exporters->all() );
		return new \WP_REST_Response( [ 'exporters' => $list ], 200 );
	}

	public function export( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $req->get_json_params() ?: [];
		$id   = (string) ( $body['format'] ?? '' );

		try {
			$exporter = $this->exporters->get( $id );
		} catch ( \DomainException $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 422 );
		}

		$result = $exporter->export( new ExportQuery(
			status:      $body['status']      ?? 'active',
			search:      $body['search']      ?? null,
			podProvider: $body['pod_provider']?? null,
			ids:         array_map( 'intval', (array) ( $body['ids'] ?? [] ) ),
			siteUrl:     home_url(),
		) );

		$payload = $result->isFile ? (string) @file_get_contents( $result->body ) : $result->body;
		return $this->raw( $payload, $exporter->mimeType(), $result->filename );
	}

	private function raw( string $body, string $mime, ?string $filename = null ): \WP_REST_Response {
		$res = new \WP_REST_Response( $body, 200 );
		$res->header( 'Content-Type', $mime );
		if ( $filename !== null ) {
			$res->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		}
		return $res;
	}
}
