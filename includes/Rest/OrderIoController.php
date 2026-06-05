<?php
/**
 * Shop by Therum — REST: order import + export.
 *
 *   POST  /shop/v1/admin/orders/import   CSV/JSON body → bulk upsert
 *   GET   /shop/v1/admin/orders/export   ?format=csv|json[&status=…&from=…&to=…]
 *
 * Auth-only. Separate controller from `AdminController` so the IO surface
 * is easy to find; it's a self-contained feature.
 */

namespace Shop\Rest;

use Shop\Exporters\OrderExporter;
use Shop\Importers\OrderImporter;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderIoController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly OrderImporter $importer,
		private readonly OrderExporter $exporter,
	) {}

	public function register(): void {
		$auth = fn(): bool => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		register_rest_route( self::NAMESPACE, '/admin/orders/import', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'import' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/orders/export', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'export' ],
			'permission_callback' => $auth,
		] );
	}

	public function import( \WP_REST_Request $req ): \WP_REST_Response {
		$body     = $req->get_json_params() ?: [];
		$csv      = (string) ( $body['csv'] ?? '' );
		$rows     = is_array( $body['rows'] ?? null ) ? $body['rows'] : null;
		$conflict = (string) ( $body['conflict'] ?? 'update' );
		$map      = is_array( $body['map'] ?? null ) ? $body['map'] : [];
		try {
			$res = $this->importer->import( $csv, $rows, $map, $conflict );
			return new \WP_REST_Response( $res, 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	public function export( \WP_REST_Request $req ): \WP_REST_Response {
		$filters = array_filter( [
			'status'    => $req->get_param( 'status' )    ?: null,
			'date_from' => $req->get_param( 'from' )      ?: null,
			'date_to'   => $req->get_param( 'to' )        ?: null,
		] );
		$format = (string) ( $req->get_param( 'format' ) ?? 'csv' );
		if ( $format === 'json' ) {
			return new \WP_REST_Response( json_decode( $this->exporter->exportJson( $filters ), true ), 200 );
		}
		$res = new \WP_REST_Response( $this->exporter->exportCsv( $filters ), 200 );
		$res->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$res->header( 'Content-Disposition', 'attachment; filename="orders.csv"' );
		return $res;
	}
}
