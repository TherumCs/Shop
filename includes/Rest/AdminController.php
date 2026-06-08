<?php
/**
 * Counter by Therum — REST: admin-scoped product + order endpoints.
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

namespace Counter\Rest;

use Counter\Admin\WooProductPatcher;
use Counter\DB;
use Counter\Repositories\OrderRepository;
use Counter\Services\RefundService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AdminController {

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly RefundService $refunds,
		private readonly WooProductPatcher $wooPatcher,
	) {}

	public const NAMESPACE = 'counter/v1';

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
			[ 'methods' => 'GET',   'callback' => [ $this, 'getProduct' ],   'permission_callback' => $auth ],
			[ 'methods' => 'PATCH', 'callback' => [ $this, 'patchProduct' ], 'permission_callback' => $auth ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/products/bulk', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'bulkProducts' ],
			'permission_callback' => $auth,
		] );

		// Set the primary image + gallery for a product. Body shape:
		//   { primary_id: int|null, gallery_ids: int[] }
		// IDs come from wp.media's attachment picker on the editor's
		// Media tab. Replaces the entire product_images rowset for the
		// product (deletes + re-inserts) under a transaction.
		register_rest_route( self::NAMESPACE, '/admin/products/(?P<id>\d+)/images', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'patchProductImages' ],
			'permission_callback' => $auth,
		] );

		// Inline edit for a single variant — sku, price, sale price (=
		// compare_at_price in our schema), stock qty. Other variant
		// fields stay read-only here.
		register_rest_route( self::NAMESPACE, '/admin/variants/(?P<id>\d+)', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'patchVariant' ],
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

		// Unlocked mode — products live in Woo, not our SQLite table.
		// Bridge through `wc_get_products()` so the same admin grid
		// works without a forced migration.
		if ( \Counter\Mode::catalogSource() === 'woo' && function_exists( 'wc_get_products' ) ) {
			return $this->listProductsFromWoo( $page, $per, $q, $status, $sort, $order );
		}

		[ $where, $bind ] = $this->whereClause( $q, $status );

		$pdo = DB::pdo();
		$countStmt = $pdo->prepare( "SELECT COUNT(*) AS c FROM products $where" );
		$countStmt->execute( $bind );
		$total = (int) ( $countStmt->fetch()['c'] ?? 0 );

		$offset = ( $page - 1 ) * $per;
		// SQLite doesn't support binding LIMIT/OFFSET, use literal values
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

		// Hydrate primary image URL for thumbnails. If the SQLite row has
		// no primary_image_id (common after a bare import where image refs
		// weren't carried), fall back to the matching WooCommerce product's
		// featured image. Counter IDs don't match WP post IDs — the importer
		// generates its own — so we join on the slug instead. One query per
		// page (≤200 rows) regardless of how many rows need the fallback.
		$slug_to_image = [];
		$missing_slugs = array_values( array_filter( array_map(
			fn( $r ) => empty( $r['primary_image_id'] ) ? (string) $r['slug'] : null,
			$rows
		) ) );

		if ( $missing_slugs ) {
			global $wpdb;
			$ph    = implode( ',', array_fill( 0, count( $missing_slugs ), '%s' ) );
			$sql   = "SELECT p.post_name AS slug, pm.meta_value AS attachment_id
			            FROM {$wpdb->posts} p
			            JOIN {$wpdb->postmeta} pm
			              ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
			           WHERE p.post_type = 'product'
			             AND p.post_name IN ( $ph )";
			$lookup = $wpdb->get_results( $wpdb->prepare( $sql, ...$missing_slugs ), ARRAY_A );
			foreach ( $lookup as $row ) {
				$slug_to_image[ $row['slug'] ] = (int) $row['attachment_id'];
			}
		}

		foreach ( $rows as &$r ) {
			$attachment_id = (int) ( $r['primary_image_id'] ?: ( $slug_to_image[ $r['slug'] ] ?? 0 ) );
			$r['image_url'] = $attachment_id
				? (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' )
				: null;
		}

		return new \WP_REST_Response( [
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'rows'     => $rows,
		], 200 );
	}

	/**
	 * Get a single product for the product editor.
	 */
	public function getProduct( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req->get_param( 'id' );

		// WooCommerce mode — fetch from Woo
		if ( \Counter\Mode::catalogSource() === 'woo' && function_exists( 'wc_get_products' ) ) {
			$wc_prod = wc_get_product( $id );
			if ( ! $wc_prod ) {
				return new \WP_REST_Response( [ 'error' => [ 'message' => 'Product not found' ] ], 404 );
			}
			return new \WP_REST_Response( $this->wooPatcher->toRow( $wc_prod ), 200 );
		}

		// Native mode — fetch from SQLite
		$pdo = DB::pdo();
		$stmt = $pdo->prepare( 'SELECT * FROM products WHERE id = :id' );
		$stmt->execute( [ ':id' => $id ] );
		$row = $stmt->fetch();

		if ( ! $row ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'Product not found' ] ], 404 );
		}

		return new \WP_REST_Response( $this->nativeProductDetail( $row ), 200 );
	}

	/**
	 * Unlocked-mode product list — wraps wc_get_products() and shapes
	 * the result into the same row schema the admin grid renders.
	 *
	 * Sort keys map:
	 *   id, created_at, updated_at, title, price, sku, stock_qty → Woo
	 *   equivalents. Columns we don't track in Woo (compare_at_price
	 *   for the parent, etc.) fall back to null.
	 *
	 * @param string $q       free-text search; matches title + SKU
	 * @param string $status  one of 'publish' / 'draft' / 'private' / ''
	 */
	private function listProductsFromWoo(
		int $page, int $per, string $q, string $status, string $sort, string $order
	): \WP_REST_Response {
		// Woo's status taxonomy uses 'publish' / 'draft' / etc. — pass
		// blanks through as "any".
		$args = [
			'limit'   => $per,
			'page'    => $page,
			'paginate'=> true,
			'orderby' => match ( $sort ) {
				'title'      => 'name',
				'created_at' => 'date',
				'updated_at' => 'modified',
				'price'      => 'price',
				'sku'        => 'sku',
				default      => 'id',
			},
			'order'   => $order,
			'status'  => $status !== '' ? [ $status ] : [ 'publish', 'draft', 'private' ],
		];
		if ( $q !== '' ) {
			// `s` matches title / content / excerpt. SKU search runs as
			// a second query and merges results — wc_get_products()
			// AND-intersects when both are passed in the same call.
			$args['s'] = $q;
		}

		$result = wc_get_products( $args );
		$total    = $result instanceof \stdClass ? (int) $result->total : count( (array) $result );
		$products = $result instanceof \stdClass ? (array) $result->products : (array) $result;

		$rows = [];
		foreach ( $products as $wc ) {
			if ( ! $wc instanceof \WC_Product ) continue;
			$rows[] = $this->wooPatcher->toRow( $wc );
		}

		return new \WP_REST_Response( [
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'rows'     => $rows,
			'source'   => 'woo',
		], 200 );
	}

	/**
	 * Shape a WC_Product into the same row dict the SQLite path emits.
	 * Money is normalized to cents (Woo stores decimal strings).
	 *
	 * @return array<string,mixed>

	/**
	 * Native-mode detail shape — for the Pure path.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function nativeProductDetail( array $row ): array {
		$id = (int) $row['id'];
		$primaryId = (int) ( $row['primary_image_id'] ?? 0 );
		return [
			'id'    => $id,
			'source'=> 'native',
			'title' => (string) ( $row['title'] ?? '' ),
			'slug'  => (string) ( $row['slug'] ?? '' ),
			'status'=> (string) ( $row['status'] ?? 'draft' ),
			'type'  => $row['has_variants'] ? 'variable' : 'simple',
			'description'       => (string) ( $row['description'] ?? '' ),
			'short_description' => (string) ( $row['short_description'] ?? '' ),
			'price' => [
				'regular'  => $row['price'] !== null ? (int) $row['price'] : null,
				'sale'     => null,
				'sale_from'=> null,
				'sale_to'  => null,
				'cost'     => $row['cost'] !== null ? (int) $row['cost'] : null,
			],
			'inventory' => [
				'sku'          => (string) ( $row['sku'] ?? '' ),
				'manage_stock' => (bool) ( $row['track_inventory'] ?? false ),
				'stock_qty'    => $row['stock_qty'] !== null ? (int) $row['stock_qty'] : null,
				'stock_status' => null,
				'backorder'    => null,
				'low_stock_amount' => null,
			],
			'shipping' => [
				'weight' => '', 'length' => '', 'width' => '', 'height' => '',
				'shipping_class' => '',
				'virtual'      => ! ( $row['is_shippable'] ?? true ),
				'downloadable' => (bool) ( $row['is_digital'] ?? false ),
			],
			'images' => [
				'primary' => $primaryId ? [ 'id' => $primaryId, 'url' => (string) wp_get_attachment_image_url( $primaryId, 'medium' ) ] : null,
				'gallery' => $this->galleryFor( (int) $row['id'] ),
			],
			'variants'   => $this->variantsFor( (int) $row['id'] ),
			'attributes' => [],
			'seo'        => [ 'meta_title' => '', 'meta_description' => '' ],
			'urls' => [
				'view'        => (string) home_url( '/product/' . $row['slug'] ),
				'edit_in_woo' => null,
			],
		];
	}

	public function patchProduct( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = $req->get_json_params() ?: [];
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'Invalid body' ] ], 400 );
		}
		// Unlocked mode — patch through Woo so we don't silently UPDATE
		// the empty SQLite products table.
		if ( \Counter\Mode::catalogSource() === 'woo' && function_exists( 'wc_get_product' ) ) {
			return $this->wooPatcher->patch( $id, $body );
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

		// Unlocked mode — route bulk through Woo.
		if ( \Counter\Mode::catalogSource() === 'woo' && function_exists( 'wc_get_product' ) ) {
			return $this->wooPatcher->bulkOps( $ids, $action, $body );
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
		// SQLite doesn't support binding LIMIT/OFFSET, use literal values
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

	// ─── Media + Variants ────────────────────────────────────────────────

	/**
	 * Build the gallery payload for a product. Returns the list of
	 * non-variant images attached to the product, ordered by position,
	 * each with a resolved attachment URL ready for the editor's Media
	 * tab to render thumbnails.
	 *
	 * @return list<array{ id:int, attachment_id:int, url:string, alt:string }>
	 */
	private function galleryFor( int $product_id ): array {
		$pdo  = DB::pdo();
		$stmt = $pdo->prepare(
			'SELECT id, attachment_id, alt_text
			   FROM product_images
			  WHERE product_id = :pid AND variant_id IS NULL
			  ORDER BY position ASC, id ASC'
		);
		$stmt->execute( [ ':pid' => $product_id ] );
		$out = [];
		foreach ( $stmt->fetchAll() as $row ) {
			$out[] = [
				'id'            => (int) $row['id'],
				'attachment_id' => (int) $row['attachment_id'],
				'url'           => (string) wp_get_attachment_image_url( (int) $row['attachment_id'], 'thumbnail' ),
				'alt'           => (string) ( $row['alt_text'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Build the variants payload for a product. Money values come back
	 * in dollars (float) so the editor's inputs don't have to deal with
	 * the cents/dollars conversion — server-side `patchVariant` handles
	 * the conversion back to cents on save.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function variantsFor( int $product_id ): array {
		$pdo  = DB::pdo();
		$stmt = $pdo->prepare(
			'SELECT id, sku, position, enabled, price, compare_at_price, stock_qty, image_id
			   FROM product_variants
			  WHERE product_id = :pid
			  ORDER BY position ASC, id ASC'
		);
		$stmt->execute( [ ':pid' => $product_id ] );

		$attrStmt = $pdo->prepare(
			'SELECT a.name AS attribute, vv.value AS value
			   FROM variant_attribute_values vv
			   JOIN attribute_values av ON av.id = vv.attribute_value_id
			   JOIN attributes a       ON a.id  = av.attribute_id
			  WHERE vv.variant_id = :vid'
		);

		$out = [];
		foreach ( $stmt->fetchAll() as $row ) {
			$attrs = [];
			try {
				$attrStmt->execute( [ ':vid' => (int) $row['id'] ] );
				foreach ( $attrStmt->fetchAll() as $a ) {
					$attrs[ (string) $a['attribute'] ] = (string) $a['value'];
				}
			} catch ( \Throwable $e ) {
				// Older imports may not have the attribute join tables
				// populated — skip gracefully so the row still renders.
			}

			$out[] = [
				'id'            => (int) $row['id'],
				'sku'           => (string) ( $row['sku'] ?? '' ),
				'enabled'       => (bool) $row['enabled'],
				'regular_price' => $row['price']            !== null ? (int) $row['price']            : null,
				'sale_price'    => $row['compare_at_price'] !== null ? (int) $row['compare_at_price'] : null,
				'stock_qty'     => $row['stock_qty']        !== null ? (int) $row['stock_qty']        : null,
				'image_id'      => $row['image_id']         !== null ? (int) $row['image_id']        : null,
				'attributes'    => $attrs,
			];
		}
		return $out;
	}

	/**
	 * PATCH /admin/products/{id}/images
	 *
	 * Body: { primary_id: int|null, gallery_ids: int[] }
	 *
	 * Replaces the entire product_images rowset for the product. We
	 * delete-and-reinsert in a transaction rather than diffing because
	 * the editor sends the final desired state — much simpler than
	 * tracking add/remove deltas client-side.
	 */
	public function patchProductImages( \WP_REST_Request $req ): \WP_REST_Response {
		$product_id  = (int) $req->get_param( 'id' );
		$body        = (array) $req->get_json_params();
		$primary_id  = isset( $body['primary_id'] ) && $body['primary_id'] !== null
			? (int) $body['primary_id']
			: null;
		$gallery_ids = array_values( array_filter( array_map( 'intval', (array) ( $body['gallery_ids'] ?? [] ) ) ) );

		try {
			DB::tx( function ( \PDO $pdo ) use ( $product_id, $primary_id, $gallery_ids ): void {
				// Update primary_image_id on the parent row.
				$pdo->prepare( 'UPDATE products SET primary_image_id = :pid WHERE id = :id' )
					->execute( [ ':pid' => $primary_id, ':id' => $product_id ] );

				// Reset the product-level gallery (variant images stay
				// untouched — they're keyed by variant_id).
				$pdo->prepare( 'DELETE FROM product_images WHERE product_id = :id AND variant_id IS NULL' )
					->execute( [ ':id' => $product_id ] );

				$ins = $pdo->prepare(
					'INSERT INTO product_images (product_id, variant_id, attachment_id, position, alt_text)
					     VALUES (:pid, NULL, :att, :pos, :alt)'
				);
				foreach ( $gallery_ids as $pos => $attachment_id ) {
					if ( $attachment_id <= 0 ) continue;
					$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
					$ins->execute( [
						':pid' => $product_id,
						':att' => $attachment_id,
						':pos' => $pos,
						':alt' => $alt,
					] );
				}
			} );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 500 );
		}

		return new \WP_REST_Response( [
			'ok'      => true,
			'images'  => [
				'primary' => $primary_id ? [ 'id' => $primary_id, 'url' => (string) wp_get_attachment_image_url( $primary_id, 'medium' ) ] : null,
				'gallery' => $this->galleryFor( $product_id ),
			],
		], 200 );
	}

	/**
	 * PATCH /admin/variants/{id}
	 *
	 * Accepts: sku, price (cents), sale_price (cents), stock_qty.
	 * Anything else in the body is ignored. Returns the updated variant
	 * row in the same shape `variantsFor()` produces so the editor can
	 * splice it directly into local state.
	 */
	public function patchVariant( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = (array) $req->get_json_params();

		$sets  = [];
		$bind  = [ ':id' => $id ];
		$map   = [
			'sku'        => 'sku',
			'price'      => 'price',
			'sale_price' => 'compare_at_price',
			'stock_qty'  => 'stock_qty',
		];
		foreach ( $map as $client_key => $col ) {
			if ( ! array_key_exists( $client_key, $body ) ) continue;
			$value = $body[ $client_key ];
			if ( in_array( $client_key, [ 'price', 'sale_price', 'stock_qty' ], true ) ) {
				$value = $value === null || $value === '' ? null : (int) $value;
			} else {
				$value = $value === null ? null : sanitize_text_field( (string) $value );
			}
			$sets[]            = "$col = :$client_key";
			$bind[":$client_key"] = $value;
		}

		if ( ! $sets ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'No editable fields supplied.' ] ], 400 );
		}

		$pdo = DB::pdo();
		try {
			$pdo->prepare( 'UPDATE product_variants SET ' . implode( ', ', $sets ) . ' WHERE id = :id' )
				->execute( $bind );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 500 );
		}

		// Fetch the parent product id so we can rebuild the variant
		// payload via the same helper the read endpoint uses.
		$row = $pdo->prepare( 'SELECT product_id FROM product_variants WHERE id = :id' );
		$row->execute( [ ':id' => $id ] );
		$product_id = (int) ( $row->fetchColumn() ?: 0 );
		$variants   = $this->variantsFor( $product_id );
		$updated    = null;
		foreach ( $variants as $v ) {
			if ( $v['id'] === $id ) { $updated = $v; break; }
		}

		return new \WP_REST_Response( [ 'ok' => true, 'variant' => $updated ], 200 );
	}
}
