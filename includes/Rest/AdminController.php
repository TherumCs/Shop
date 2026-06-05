<?php
/**
 * Shop by Therum — REST: admin-scoped product + order endpoints.
 *
 * Powers the spreadsheet-style manager. All routes require manage_woocommerce.
 *
 * Routes (namespace shop/v1):
 *
 *   GET    /admin/products            list + search + sort + filter + paginate
 *   PATCH  /admin/products/{id}        single-field inline edit
 *   POST   /admin/products/bulk        bulk action (delete, duplicate, status, set)
 *
 *   (orders endpoints follow same pattern in next chunk)
 *
 * Bulk action shape:
 *
 *   { "action": "delete",   "ids": [1,2,3] }
 *   { "action": "duplicate","ids": [1,2,3] }
 *   { "action": "status",   "ids": [...], "value": "active|draft|archived" }
 *   { "action": "set",      "ids": [...], "field": "price", "value": 2999 }
 *
 * The "set" action is the universal "change a field across many rows"
 * primitive — sale price, stock, vendor, etc.
 */

namespace Shop\Rest;

use Shop\DB;
use Shop\Repositories\OrderRepository;
use Shop\Services\RefundService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AdminController {

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly RefundService $refunds,
	) {}

	public const NAMESPACE = 'shop/v1';

	/** Fields safe to inline-edit via PATCH (whitelist). */
	private const EDITABLE = [
		'title'          => 'text',
		'slug'           => 'slug',
		'status'         => 'enum:draft,active,archived',
		'price'          => 'cents',
		'compare_at_price' => 'cents',
		'cost'           => 'cents',
		'sku'            => 'text',
		'stock_qty'      => 'int',
		'has_variants'   => 'bool',
		'is_shippable'   => 'bool',
		'is_digital'     => 'bool',
		'is_pod'         => 'bool',
		'track_inventory'=> 'bool',
	];

	/** Columns the list endpoint sorts on (whitelist). */
	private const SORTABLE = [ 'id', 'title', 'price', 'stock_qty', 'status', 'created_at', 'updated_at' ];

	/** Editable order fields. Orders are mostly immutable — status and
	 *  internal notes are the only safe-to-edit columns. */
	private const ORDER_EDITABLE = [
		'status'         => 'enum:pending,processing,on-hold,completed,cancelled,refunded,failed',
		'internal_notes' => 'text',
	];

	private const ORDER_SORTABLE = [
		'id', 'number', 'email', 'status', 'grand_total', 'created_at', 'paid_at', 'updated_at',
	];

	public function register(): void {
		$auth = function (): bool {
			return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		};

		register_rest_route( self::NAMESPACE, '/admin/products', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'listProducts' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/products/(?P<id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'patchProduct' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/products/bulk', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'bulkProducts' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/orders', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'listOrders' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/orders/(?P<id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'patchOrder' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/orders/bulk', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'bulkOrders' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/orders/(?P<id>\d+)/refund', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'refundOrder' ],
			'permission_callback' => $auth,
			'args'                => [
				'amount' => [ 'type' => 'integer', 'required' => true ],
				'reason' => [ 'type' => 'string',  'required' => false ],
			],
		] );
	}

	public function refundOrder( \WP_REST_Request $req ): \WP_REST_Response {
		$id    = (int) $req->get_param( 'id' );
		$cents = (int) $req->get_param( 'amount' );
		$reason = (string) ( $req->get_param( 'reason' ) ?? 'customer_request' );

		$order = $this->orders->findById( $id );
		if ( $order === null ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'Order not found' ] ], 404 );
		}

		try {
			$refund_id = $this->refunds->refund(
				order:       $order,
				amountCents: $cents,
				reason:      $reason,
				initiatedBy: 'admin',
				userId:      get_current_user_id() ?: null,
			);
		} catch ( \DomainException $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 422 );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 502 );
		}

		return new \WP_REST_Response( [ 'refund_id' => $refund_id ], 200 );
	}

	// ─── Handlers ────────────────────────────────────────────────────────

	public function listProducts( \WP_REST_Request $req ): \WP_REST_Response {
		$page    = max( 1, (int) ( $req->get_param( 'page' )    ?: 1 ) );
		$per     = max( 1, min( 200, (int) ( $req->get_param( 'per_page' ) ?: 50 ) ) );
		$q       = trim( (string) $req->get_param( 'q' ) );
		$status  = (string) $req->get_param( 'status' );
		$sort    = (string) ( $req->get_param( 'sort' ) ?: 'id' );
		$order   = strtolower( (string) $req->get_param( 'order' ) ) === 'asc' ? 'ASC' : 'DESC';

		if ( ! in_array( $sort, self::SORTABLE, true ) ) $sort = 'id';

		[ $where, $bind ] = $this->whereClause( $q, $status );

		$pdo = DB::pdo();
		$count = (int) $pdo->prepare( "SELECT COUNT(*) FROM products $where" )
			->execute( $bind ) ?: 0;
		// PDOStatement::execute returns bool — re-do count properly:
		$countStmt = $pdo->prepare( "SELECT COUNT(*) AS c FROM products $where" );
		$countStmt->execute( $bind );
		$total = (int) ( $countStmt->fetch()['c'] ?? 0 );

		$offset = ( $page - 1 ) * $per;
		$stmt = $pdo->prepare(
			"SELECT id, uuid, slug, title, status, has_variants, is_shippable,
			        is_digital, is_pod, track_inventory,
			        price, compare_at_price, cost, sku, stock_qty,
			        primary_image_id, created_at, updated_at
			   FROM products $where
			   ORDER BY $sort $order
			   LIMIT $per OFFSET $offset"
		);
		$stmt->execute( $bind );
		$rows = $stmt->fetchAll();

		// Hydrate primary image URL for thumbnails
		foreach ( $rows as &$r ) {
			$r['image_url'] = $r['primary_image_id']
				? (string) wp_get_attachment_image_url( (int) $r['primary_image_id'], 'thumbnail' )
				: null;
		}

		return new \WP_REST_Response( [
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'rows'     => $rows,
		], 200 );
	}

	public function patchProduct( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = $req->get_json_params() ?: [];
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'Invalid body' ] ], 400 );
		}

		[ $sets, $bind, $errors ] = $this->prepareUpdate( $body );

		if ( $errors ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => implode( '; ', $errors ) ] ], 422 );
		}
		if ( ! $sets ) {
			return new \WP_REST_Response( [ 'updated' => 0 ], 200 );
		}

		$bind[':id'] = $id;
		$sets[] = 'updated_at = unixepoch()';
		$sql    = 'UPDATE products SET ' . implode( ', ', $sets ) . ' WHERE id = :id';
		DB::pdo()->prepare( $sql )->execute( $bind );

		return new \WP_REST_Response( [ 'updated' => 1 ], 200 );
	}

	public function bulkProducts( \WP_REST_Request $req ): \WP_REST_Response {
		$body   = $req->get_json_params() ?: [];
		$action = (string) ( $body['action'] ?? '' );
		$ids    = array_values( array_filter( array_map( 'intval', (array) ( $body['ids'] ?? [] ) ) ) );

		if ( ! $ids ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'No ids' ] ], 400 );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$pdo          = DB::pdo();

		try {
			$count = DB::tx( function () use ( $action, $ids, $body, $placeholders, $pdo ): int {
				switch ( $action ) {
					case 'delete':
						$stmt = $pdo->prepare( "DELETE FROM products WHERE id IN ($placeholders)" );
						$stmt->execute( $ids );
						return $stmt->rowCount();

					case 'duplicate':
						$copied = 0;
						foreach ( $ids as $id ) {
							if ( $this->duplicateOne( $id ) ) $copied++;
						}
						return $copied;

					case 'status':
						$value = (string) ( $body['value'] ?? '' );
						if ( ! in_array( $value, [ 'draft', 'active', 'archived' ], true ) ) {
							throw new \DomainException( 'Invalid status' );
						}
						$stmt = $pdo->prepare(
							"UPDATE products
							    SET status = ?, updated_at = unixepoch()
							  WHERE id IN ($placeholders)"
						);
						$stmt->execute( array_merge( [ $value ], $ids ) );
						return $stmt->rowCount();

					case 'set':
						$field = (string) ( $body['field'] ?? '' );
						$value = $body['value'] ?? null;
						if ( ! isset( self::EDITABLE[ $field ] ) ) {
							throw new \DomainException( 'Field not editable: ' . $field );
						}
						$normalized = $this->normalizeValue( $field, $value );
						if ( $normalized === '__invalid__' ) {
							throw new \DomainException( 'Invalid value for ' . $field );
						}
						$stmt = $pdo->prepare(
							"UPDATE products
							    SET $field = ?, updated_at = unixepoch()
							  WHERE id IN ($placeholders)"
						);
						$stmt->execute( array_merge( [ $normalized ], $ids ) );
						return $stmt->rowCount();

					default:
						throw new \DomainException( 'Unknown action: ' . $action );
				}
			} );
		} catch ( \DomainException $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 422 );
		}

		return new \WP_REST_Response( [ 'count' => $count ], 200 );
	}

	// ─── Order handlers ──────────────────────────────────────────────────

	public function listOrders( \WP_REST_Request $req ): \WP_REST_Response {
		$page    = max( 1, (int) ( $req->get_param( 'page' )    ?: 1 ) );
		$per     = max( 1, min( 200, (int) ( $req->get_param( 'per_page' ) ?: 50 ) ) );
		$q       = trim( (string) $req->get_param( 'q' ) );
		$status  = (string) $req->get_param( 'status' );
		$sort    = (string) ( $req->get_param( 'sort' ) ?: 'created_at' );
		$order   = strtolower( (string) $req->get_param( 'order' ) ) === 'asc' ? 'ASC' : 'DESC';
		if ( ! in_array( $sort, self::ORDER_SORTABLE, true ) ) $sort = 'created_at';

		[ $where, $bind ] = $this->orderWhereClause( $q, $status );

		$pdo = DB::pdo();
		$countStmt = $pdo->prepare( "SELECT COUNT(*) AS c FROM orders $where" );
		$countStmt->execute( $bind );
		$total = (int) ( $countStmt->fetch()['c'] ?? 0 );

		$offset = ( $page - 1 ) * $per;
		$stmt = $pdo->prepare(
			"SELECT id, number, user_id, email, currency, status,
			        subtotal, shipping_total, tax_total, discount_total, grand_total, refunded_total,
			        payment_provider, payment_method, payment_intent_id,
			        paid_at, created_at, updated_at
			   FROM orders $where
			   ORDER BY $sort $order
			   LIMIT $per OFFSET $offset"
		);
		$stmt->execute( $bind );
		$rows = $stmt->fetchAll();

		// Hydrate item counts in one extra query to avoid N+1
		$ids = array_column( $rows, 'id' );
		$counts = [];
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '?' ) );
			$cstmt = $pdo->prepare(
				"SELECT order_id, SUM(quantity) AS qty
				   FROM order_items
				  WHERE order_id IN ($placeholders)
				  GROUP BY order_id"
			);
			$cstmt->execute( $ids );
			foreach ( $cstmt->fetchAll() as $r ) {
				$counts[ (int) $r['order_id'] ] = (int) $r['qty'];
			}
		}
		foreach ( $rows as &$r ) {
			$r['item_count'] = $counts[ (int) $r['id'] ] ?? 0;
		}

		return new \WP_REST_Response( [
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'rows'     => $rows,
		], 200 );
	}

	public function patchOrder( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = $req->get_json_params() ?: [];
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'Invalid body' ] ], 400 );
		}

		$sets = []; $bind = []; $errs = [];
		foreach ( $body as $field => $value ) {
			if ( ! isset( self::ORDER_EDITABLE[ $field ] ) ) continue;
			$normalized = $this->normalizeValueFor( self::ORDER_EDITABLE[ $field ], $value );
			if ( $normalized === '__invalid__' ) {
				$errs[] = "Invalid value for $field";
				continue;
			}
			$sets[] = "$field = :$field";
			$bind[":$field"] = $normalized;
		}

		if ( $errs ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => implode( '; ', $errs ) ] ], 422 );
		}
		if ( ! $sets ) {
			return new \WP_REST_Response( [ 'updated' => 0 ], 200 );
		}

		$bind[':id'] = $id;
		$sets[] = 'updated_at = unixepoch()';
		$sql    = 'UPDATE orders SET ' . implode( ', ', $sets ) . ' WHERE id = :id';
		DB::pdo()->prepare( $sql )->execute( $bind );

		return new \WP_REST_Response( [ 'updated' => 1 ], 200 );
	}

	public function bulkOrders( \WP_REST_Request $req ): \WP_REST_Response {
		$body   = $req->get_json_params() ?: [];
		$action = (string) ( $body['action'] ?? '' );
		$ids    = array_values( array_filter( array_map( 'intval', (array) ( $body['ids'] ?? [] ) ) ) );

		if ( ! $ids ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'No ids' ] ], 400 );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$pdo          = DB::pdo();

		try {
			$count = DB::tx( function () use ( $action, $ids, $body, $placeholders, $pdo ): int {
				switch ( $action ) {
					case 'status':
						$value = (string) ( $body['value'] ?? '' );
						$allowed = [ 'pending','processing','on-hold','completed','cancelled','refunded','failed' ];
						if ( ! in_array( $value, $allowed, true ) ) {
							throw new \DomainException( 'Invalid status' );
						}
						$stmt = $pdo->prepare(
							"UPDATE orders
							    SET status = ?, updated_at = unixepoch()
							  WHERE id IN ($placeholders)"
						);
						$stmt->execute( array_merge( [ $value ], $ids ) );
						return $stmt->rowCount();

					case 'delete':
						// Orders are immutable; "delete" here means archive
						// (set status = cancelled). We never actually destroy
						// orders from this surface to avoid losing audit trail.
						$stmt = $pdo->prepare(
							"UPDATE orders
							    SET status = 'cancelled', updated_at = unixepoch()
							  WHERE id IN ($placeholders)"
						);
						$stmt->execute( $ids );
						return $stmt->rowCount();

					default:
						throw new \DomainException( 'Unknown action: ' . $action );
				}
			} );
		} catch ( \DomainException $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 422 );
		}

		return new \WP_REST_Response( [ 'count' => $count ], 200 );
	}

	/**
	 * @return array{0:string, 1:array<string,mixed>}
	 */
	private function orderWhereClause( string $q, string $status ): array {
		$bits = [];
		$bind = [];
		if ( $q !== '' ) {
			$bits[] = '(number LIKE :q OR email LIKE :q OR payment_intent_id LIKE :q)';
			$bind[':q'] = '%' . $q . '%';
		}
		if ( $status !== '' ) {
			$bits[] = 'status = :status';
			$bind[':status'] = $status;
		}
		return [ $bits ? 'WHERE ' . implode( ' AND ', $bits ) : '', $bind ];
	}

	private function normalizeValueFor( string $type, mixed $value ): mixed {
		if ( str_starts_with( $type, 'enum:' ) ) {
			$allowed = explode( ',', substr( $type, 5 ) );
			return in_array( (string) $value, $allowed, true ) ? (string) $value : '__invalid__';
		}
		return match ( $type ) {
			'text'  => $value === null ? null : (string) $value,
			'cents' => $value === null || $value === '' ? null
				: ( is_numeric( $value ) ? (int) $value : '__invalid__' ),
			'int'   => $value === null || $value === '' ? null
				: ( is_numeric( $value ) ? (int) $value : '__invalid__' ),
			'bool'  => $value ? 1 : 0,
			default => '__invalid__',
		};
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * @return array{0:string, 1:array<string,mixed>}
	 */
	private function whereClause( string $q, string $status ): array {
		$bits = [];
		$bind = [];
		if ( $q !== '' ) {
			$bits[] = '(title LIKE :q OR sku LIKE :q OR slug LIKE :q)';
			$bind[':q'] = '%' . $q . '%';
		}
		if ( $status !== '' && in_array( $status, [ 'draft', 'active', 'archived' ], true ) ) {
			$bits[] = 'status = :status';
			$bind[':status'] = $status;
		}
		return [ $bits ? 'WHERE ' . implode( ' AND ', $bits ) : '', $bind ];
	}

	/**
	 * Translate a JSON edit body into SQL set list + bound values.
	 *
	 * @param array<string,mixed> $body
	 * @return array{0:string[], 1:array<string,mixed>, 2:string[]}
	 */
	private function prepareUpdate( array $body ): array {
		$sets = [];
		$bind = [];
		$errs = [];
		foreach ( $body as $field => $value ) {
			if ( ! isset( self::EDITABLE[ $field ] ) ) continue;
			$normalized = $this->normalizeValue( $field, $value );
			if ( $normalized === '__invalid__' ) {
				$errs[] = "Invalid value for $field";
				continue;
			}
			$sets[] = "$field = :$field";
			$bind[":$field"] = $normalized;
		}
		return [ $sets, $bind, $errs ];
	}

	private function normalizeValue( string $field, mixed $value ): mixed {
		$type = self::EDITABLE[ $field ] ?? 'text';
		if ( str_starts_with( $type, 'enum:' ) ) {
			$allowed = explode( ',', substr( $type, 5 ) );
			return in_array( (string) $value, $allowed, true ) ? (string) $value : '__invalid__';
		}
		return match ( $type ) {
			'text'  => $value === null ? null : (string) $value,
			'slug'  => sanitize_title( (string) $value ),
			'cents' => $value === null || $value === '' ? null
				: ( is_numeric( $value ) ? (int) $value : '__invalid__' ),
			'int'   => $value === null || $value === '' ? null
				: ( is_numeric( $value ) ? (int) $value : '__invalid__' ),
			'bool'  => $value ? 1 : 0,
			default => '__invalid__',
		};
	}

	private function duplicateOne( int $id ): bool {
		$pdo = DB::pdo();
		$stmt = $pdo->prepare( "SELECT * FROM products WHERE id = :i" );
		$stmt->execute( [ ':i' => $id ] );
		$row = $stmt->fetch();
		if ( ! $row ) return false;

		// Strip identity columns; bump title; new uuid + slug
		unset( $row['id'], $row['created_at'], $row['updated_at'] );
		$row['uuid']  = wp_generate_uuid4();
		$row['slug']  = $row['slug'] . '-copy-' . substr( $row['uuid'], 0, 6 );
		$row['title'] = $row['title'] . ' (copy)';
		$row['status'] = 'draft';

		$cols = array_keys( $row );
		$placeholders = implode( ',', array_map( fn( string $c ): string => ':' . $c, $cols ) );
		$bind = [];
		foreach ( $row as $k => $v ) $bind[ ':' . $k ] = $v;

		$pdo->prepare(
			'INSERT INTO products (' . implode( ',', $cols ) . ', created_at, updated_at)
			 VALUES (' . $placeholders . ', unixepoch(), unixepoch())'
		)->execute( $bind );

		$new_id = (int) $pdo->lastInsertId();

		// Duplicate variants
		$vstmt = $pdo->prepare( "SELECT * FROM product_variants WHERE product_id = :p" );
		$vstmt->execute( [ ':p' => $id ] );
		while ( $variant = $vstmt->fetch() ) {
			unset( $variant['id'] );
			$variant['uuid']       = wp_generate_uuid4();
			$variant['product_id'] = $new_id;
			$vcols = array_keys( $variant );
			$vph   = implode( ',', array_map( fn( string $c ): string => ':' . $c, $vcols ) );
			$vbind = [];
			foreach ( $variant as $k => $v ) $vbind[ ':' . $k ] = $v;
			$pdo->prepare(
				'INSERT INTO product_variants (' . implode( ',', $vcols ) . ') VALUES (' . $vph . ')'
			)->execute( $vbind );
		}

		return true;
	}
}
