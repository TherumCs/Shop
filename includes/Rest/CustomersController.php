<?php
/**
 * Shop by Therum — REST: customers.
 *
 *   GET    /shop/v1/admin/customers              list (search, paginate)
 *   POST   /shop/v1/admin/customers              create
 *   GET    /shop/v1/admin/customers/{id}         get one
 *   PUT    /shop/v1/admin/customers/{id}         update
 *   DELETE /shop/v1/admin/customers/{id}         delete
 *
 *   POST   /shop/v1/admin/customers/import       CSV/JSON in body → upsert
 *   GET    /shop/v1/admin/customers/export       returns CSV or JSON
 *
 * Auth-only — no public read. PII never leaks to the storefront.
 */

namespace Shop\Rest;

use Shop\Exporters\CustomerExporter;
use Shop\Importers\CustomerImporter;
use Shop\Repositories\CustomerRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CustomersController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly CustomerRepository $customers,
		private readonly CustomerImporter   $importer,
		private readonly CustomerExporter   $exporter,
	) {}

	public function register(): void {
		$auth = fn(): bool => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		register_rest_route( self::NAMESPACE, '/admin/customers', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list' ],   'permission_callback' => $auth ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( self::NAMESPACE, '/admin/customers/(?P<id>\d+)', [
			[ 'methods' => 'GET',    'callback' => [ $this, 'get' ],    'permission_callback' => $auth ],
			[ 'methods' => 'PUT',    'callback' => [ $this, 'update' ], 'permission_callback' => $auth ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( self::NAMESPACE, '/admin/customers/import', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'import' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/customers/export', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'export' ],
			'permission_callback' => $auth,
		] );
	}

	public function list( \WP_REST_Request $req ): \WP_REST_Response {
		$search = (string) ( $req->get_param( 'q' ) ?? '' );
		$limit  = max( 1, min( 500, (int) ( $req->get_param( 'limit' ) ?? 100 ) ) );
		$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?? 0 ) );
		$rows   = $this->customers->list( $search ?: null, $limit, $offset );
		return new \WP_REST_Response( [
			'customers' => array_map( [ $this, 'serialize' ], $rows ),
			'total'     => $this->customers->count( $search ?: null ),
		], 200 );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body  = $req->get_json_params() ?: [];
		$email = (string) ( $body['email'] ?? '' );
		if ( $email === '' ) return new \WP_REST_Response( [ 'error' => 'Email required.' ], 400 );
		$res = $this->customers->upsertByEmail( $email, $body, 'update' );
		return new \WP_REST_Response( $this->serialize( $res['customer'] ), 200 );
	}

	public function get( \WP_REST_Request $req ): \WP_REST_Response {
		$c = $this->customers->findById( (int) $req->get_param( 'id' ) );
		if ( ! $c ) return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		return new \WP_REST_Response( $this->serialize( $c ), 200 );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! $this->customers->findById( $id ) ) return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		$this->customers->save( $id, $req->get_json_params() ?: [] );
		return new \WP_REST_Response( $this->serialize( $this->customers->findById( $id ) ), 200 );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$this->customers->delete( (int) $req->get_param( 'id' ) );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function import( \WP_REST_Request $req ): \WP_REST_Response {
		$body          = $req->get_json_params() ?: [];
		$csv           = (string) ( $body['csv'] ?? '' );
		$rows          = is_array( $body['rows'] ?? null ) ? $body['rows'] : null;
		$conflict      = (string) ( $body['conflict'] ?? 'update' );
		$createWpUsers = ! empty( $body['create_wp_users'] );
		$map           = is_array( $body['map'] ?? null ) ? $body['map'] : [];
		try {
			$res = $this->importer->import( $csv, $rows, $map, $conflict, $createWpUsers );
			return new \WP_REST_Response( $res, 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	public function export( \WP_REST_Request $req ): \WP_REST_Response {
		$format = (string) ( $req->get_param( 'format' ) ?? 'csv' );
		if ( $format === 'json' ) {
			return new \WP_REST_Response( json_decode( $this->exporter->exportJson(), true ), 200 );
		}
		// CSV — return as a string body so the browser handles download.
		$res = new \WP_REST_Response( $this->exporter->exportCsv(), 200 );
		$res->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$res->header( 'Content-Disposition', 'attachment; filename="customers.csv"' );
		return $res;
	}

	/** @return array<string,mixed> */
	private function serialize( \Shop\Models\Customer $c ): array {
		return [
			'id'                => $c->id,
			'uuid'              => $c->uuid,
			'email'             => $c->email,
			'first_name'        => $c->first_name,
			'last_name'         => $c->last_name,
			'phone'             => $c->phone,
			'wp_user_id'        => $c->wp_user_id,
			'accepts_marketing' => $c->accepts_marketing,
			'address_line1'     => $c->address_line1,
			'address_line2'     => $c->address_line2,
			'city'              => $c->city,
			'state'             => $c->state,
			'postal_code'       => $c->postal_code,
			'country'           => $c->country,
			'tags'              => $c->tags,
			'orders_count'      => $c->orders_count,
			'total_spent_cents' => $c->total_spent_cents,
			'last_order_at'     => $c->last_order_at,
			'created_at'        => $c->created_at,
			'updated_at'        => $c->updated_at,
		];
	}
}
