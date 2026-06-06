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

use Counter\DB;
use Counter\Repositories\OrderRepository;
use Counter\Services\RefundService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AdminController {

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly RefundService $refunds,
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
		$stmt = $pdo->prepare(
			"SELECT id, uuid, slug, title, status, has_variants, is_shippable,
			        is_digital, is_pod, track_inventory,
			        price, compare_at_price, cost, sku, stock_qty,
			        primary_image_id, created_at, updated_at
			   FROM products $where
			   ORDER BY $sort $order
			   LIMIT :per OFFSET :offset"
		);
		$stmt->bindValue( ':per', $per, \PDO::PARAM_INT );
		$stmt->bindValue( ':offset', $offset, \PDO::PARAM_INT );
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
			$rows[] = $this->wcProductToRow( $wc );
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
	 */
	private function wcProductToRow( \WC_Product $wc ): array {
		// Variable products don't have a single regular_price on the
		// parent — fall back to get_price() which Woo resolves to the
		// lowest variant price. Sale + compare logic still keys off
		// the regular_price when present.
		$regular      = $this->wcPriceCents( $wc->get_regular_price() );
		$priceCents   = $regular ?? $this->wcPriceCents( $wc->get_price() );
		$compareCents = $this->wcPriceCents( $wc->get_sale_price() ) !== null ? $regular : null;
		$imageId = (int) $wc->get_image_id();
		return [
			'id'                => $wc->get_id(),
			'uuid'              => 'wc-' . $wc->get_id(),
			'slug'              => $wc->get_slug(),
			'title'             => $wc->get_name(),
			'status'            => $wc->get_status(),
			'has_variants'      => $wc->is_type( 'variable' ) ? 1 : 0,
			'is_shippable'      => $wc->is_virtual() ? 0 : 1,
			'is_digital'        => $wc->is_downloadable() ? 1 : 0,
			'is_pod'            => 0,
			'track_inventory'   => $wc->managing_stock() ? 1 : 0,
			'price'             => $priceCents,
			'compare_at_price'  => $compareCents,
			'cost'              => null,
			'sku'               => $wc->get_sku() ?: null,
			'stock_qty'         => $wc->managing_stock() ? (int) $wc->get_stock_quantity() : null,
			'primary_image_id'  => $imageId ?: null,
			'image_url'         => $imageId ? (string) wp_get_attachment_image_url( $imageId, 'thumbnail' ) : null,
			'created_at'        => $wc->get_date_created() ? $wc->get_date_created()->getTimestamp() : null,
			'updated_at'        => $wc->get_date_modified() ? $wc->get_date_modified()->getTimestamp() : null,
		];
	}

	private function wcPriceCents( $value ): ?int {
		if ( $value === '' || $value === null ) return null;
		return (int) round( ( (float) $value ) * 100 );
	}

	/**
	 * Inline-edit a Woo product. Maps our flat field schema to the
	 * Woo setter API:
	 *   title       → name
	 *   status      → status (publish / draft / private / pending)
	 *   sku         → sku
	 *   stock_qty   → stock_quantity (also enables manage_stock)
	 *   price       → regular_price (cents in → decimal string out)
	 *   compare_at_price → not editable on parents (variant-level only)
	 *
	 * @param array<string,mixed> $body
	 */
	private function patchWooProduct( int $id, array $body ): \WP_REST_Response {
		$wc = wc_get_product( $id );
		if ( ! $wc instanceof \WC_Product ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'Product not found.' ] ], 404 );
		}
		$ok = [];

		// Top-level scalar fields (used by inline-grid edits + drawer's
		// General tab).
		foreach ( [ 'title', 'slug', 'status', 'description', 'short_description' ] as $field ) {
			if ( ! array_key_exists( $field, $body ) ) continue;
			$value = $body[ $field ];
			match ( $field ) {
				'title'             => $wc->set_name( (string) $value ),
				'slug'              => $wc->set_slug( (string) $value ),
				'status'            => $wc->set_status( (string) $value ),
				'description'       => $wc->set_description( (string) $value ),
				'short_description' => $wc->set_short_description( (string) $value ),
			};
			$ok[] = $field;
		}

		// Legacy flat inline-edit fields (sku/stock_qty/price kept here
		// so the grid's PATCH still works post-refactor).
		if ( array_key_exists( 'sku', $body ) )       { $wc->set_sku( (string) $body['sku'] ); $ok[] = 'sku'; }
		if ( array_key_exists( 'stock_qty', $body ) ) {
			$v = $body['stock_qty'];
			if ( $v === null || $v === '' ) $wc->set_manage_stock( false );
			else { $wc->set_manage_stock( true ); $wc->set_stock_quantity( (int) $v ); }
			$ok[] = 'stock_qty';
		}
		if ( array_key_exists( 'price', $body ) ) {
			$v = $body['price'];
			$wc->set_regular_price( ( $v === null || $v === '' ) ? '' : number_format( ( (int) $v ) / 100, 2, '.', '' ) );
			$ok[] = 'price';
		}

		// Drawer-nested groups: price{}, inventory{}, shipping{}, images{}, seo{}
		if ( is_array( $body['price'] ?? null ) ) {
			$p = $body['price'];
			if ( array_key_exists( 'regular', $p ) ) $wc->set_regular_price( $p['regular'] === null ? '' : number_format( ( (int) $p['regular'] ) / 100, 2, '.', '' ) );
			if ( array_key_exists( 'sale', $p ) )    $wc->set_sale_price( $p['sale'] === null ? '' : number_format( ( (int) $p['sale'] ) / 100, 2, '.', '' ) );
			if ( array_key_exists( 'sale_from', $p ) ) $wc->set_date_on_sale_from( $p['sale_from'] ?: null );
			if ( array_key_exists( 'sale_to',   $p ) ) $wc->set_date_on_sale_to(   $p['sale_to']   ?: null );
			$ok[] = 'price';
		}
		if ( is_array( $body['inventory'] ?? null ) ) {
			$i = $body['inventory'];
			if ( array_key_exists( 'sku', $i ) )          $wc->set_sku( (string) $i['sku'] );
			if ( array_key_exists( 'manage_stock', $i ) ) $wc->set_manage_stock( (bool) $i['manage_stock'] );
			if ( array_key_exists( 'stock_qty', $i ) && $wc->managing_stock() ) $wc->set_stock_quantity( (int) $i['stock_qty'] );
			if ( array_key_exists( 'stock_status', $i ) && $i['stock_status'] ) $wc->set_stock_status( (string) $i['stock_status'] );
			if ( array_key_exists( 'backorder', $i ) && $i['backorder'] !== null ) $wc->set_backorders( (string) $i['backorder'] );
			if ( array_key_exists( 'low_stock_amount', $i ) ) $wc->set_low_stock_amount( $i['low_stock_amount'] === null ? '' : (string) (int) $i['low_stock_amount'] );
			$ok[] = 'inventory';
		}
		if ( is_array( $body['shipping'] ?? null ) ) {
			$s = $body['shipping'];
			if ( array_key_exists( 'weight', $s ) )         $wc->set_weight( (string) $s['weight'] );
			if ( array_key_exists( 'length', $s ) )         $wc->set_length( (string) $s['length'] );
			if ( array_key_exists( 'width',  $s ) )         $wc->set_width(  (string) $s['width'] );
			if ( array_key_exists( 'height', $s ) )         $wc->set_height( (string) $s['height'] );
			if ( array_key_exists( 'shipping_class', $s ) ) $wc->set_shipping_class_id( (int) wp_get_term_taxonomy( $s['shipping_class'] ?: 0, 'product_shipping_class' ) );
			if ( array_key_exists( 'virtual', $s ) )        $wc->set_virtual( (bool) $s['virtual'] );
			if ( array_key_exists( 'downloadable', $s ) )   $wc->set_downloadable( (bool) $s['downloadable'] );
			$ok[] = 'shipping';
		}
		if ( is_array( $body['images'] ?? null ) ) {
			$im = $body['images'];
			if ( isset( $im['primary']['id'] ) ) $wc->set_image_id( (int) $im['primary']['id'] );
			if ( isset( $im['gallery'] ) && is_array( $im['gallery'] ) ) {
				$wc->set_gallery_image_ids( array_filter( array_map( fn( $g ) => (int) ( $g['id'] ?? 0 ), $im['gallery'] ) ) );
			}
			$ok[] = 'images';
		}
		if ( is_array( $body['seo'] ?? null ) ) {
			$s = $body['seo'];
			if ( isset( $s['meta_title'] ) )       update_post_meta( $id, '_yoast_wpseo_title',    (string) $s['meta_title'] );
			if ( isset( $s['meta_description'] ) ) update_post_meta( $id, '_yoast_wpseo_metadesc', (string) $s['meta_description'] );
			$ok[] = 'seo';
		}

		$wc->save();
		return new \WP_REST_Response( [ 'ok' => true, 'updated' => $ok, 'row' => $this->wcProductToRow( $wc ) ], 200 );
	}

	/**
	 * Bulk ops on Woo products. Supports: delete (trash), duplicate
	 * (uses WC_Admin_Duplicate_Product), set_status, set (apply a
	 * field map to every selected product).
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $body
	 */
	private function bulkWooProducts( string $action, array $ids, array $body ): \WP_REST_Response {
		$touched = 0;
		switch ( $action ) {
			case 'delete':
				foreach ( $ids as $id ) {
					$wc = wc_get_product( $id );
					if ( $wc && $wc->delete( /* force_delete = */ false ) ) $touched++;
				}
				break;
			case 'duplicate':
				if ( ! class_exists( '\WC_Admin_Duplicate_Product' ) ) {
					require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
				}
				$dup = new \WC_Admin_Duplicate_Product();
				foreach ( $ids as $id ) {
					$wc = wc_get_product( $id );
					if ( $wc && $dup->product_duplicate( $wc ) ) $touched++;
				}
				break;
			case 'set_status':
				$status = (string) ( $body['status'] ?? '' );
				foreach ( $ids as $id ) {
					$wc = wc_get_product( $id );
					if ( $wc ) { $wc->set_status( $status ); $wc->save(); $touched++; }
				}
				break;
			case 'set':
				$set = (array) ( $body['set'] ?? [] );
				foreach ( $ids as $id ) {
					// Reuse patch logic per row so field mapping stays
					// in one place.
					$r = $this->patchWooProduct( $id, $set );
					if ( ! empty( $r->get_data()['ok'] ) ) $touched++;
				}
				break;
			default:
				return new \WP_REST_Response( [ 'error' => [ 'message' => "Unknown bulk action '$action'." ] ], 400 );
		}
		return new \WP_REST_Response( [ 'ok' => true, 'count' => $touched, 'action' => $action ], 200 );
	}

	/**
	 * GET /admin/products/{id} — return everything the drawer editor
	 * needs. Mode-aware: pulls from Woo in Unlocked mode, from our
	 * SQLite table otherwise. Shape is intentionally flat per tab so
	 * the frontend can map straight onto form state.
	 */
	public function getProduct( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req->get_param( 'id' );

		if ( \Counter\Mode::catalogSource() === 'woo' && function_exists( 'wc_get_product' ) ) {
			$wc = wc_get_product( $id );
			if ( ! $wc instanceof \WC_Product ) {
				return new \WP_REST_Response( [ 'error' => [ 'message' => 'Product not found.' ] ], 404 );
			}
			return new \WP_REST_Response( $this->wcProductDetail( $wc ), 200 );
		}

		// Native (Pure) path — pull a single row, hydrate
		$pdo = DB::pdo();
		$stmt = $pdo->prepare( "SELECT * FROM products WHERE id = :id" );
		$stmt->execute( [ ':id' => $id ] );
		$row = $stmt->fetch();
		if ( ! $row ) return new \WP_REST_Response( [ 'error' => [ 'message' => 'Product not found.' ] ], 404 );

		return new \WP_REST_Response( $this->nativeProductDetail( $row ), 200 );
	}

	/**
	 * Enrich a WC_Product into the drawer's expected shape.
	 *
	 * @return array<string,mixed>
	 */
	private function wcProductDetail( \WC_Product $wc ): array {
		$galleryIds = array_filter( array_map( 'intval', $wc->get_gallery_image_ids() ) );
		$gallery    = array_map( fn( $id ) => [
			'id'  => $id,
			'url' => (string) wp_get_attachment_image_url( $id, 'medium' ),
		], $galleryIds );

		// Variants (only meaningful for variable products)
		$variants = [];
		if ( $wc->is_type( 'variable' ) ) {
			foreach ( $wc->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v instanceof \WC_Product_Variation ) continue;
				$variants[] = [
					'id'             => $v->get_id(),
					'sku'            => (string) $v->get_sku(),
					'regular_price'  => $this->wcPriceCents( $v->get_regular_price() ),
					'sale_price'     => $this->wcPriceCents( $v->get_sale_price() ),
					'stock_qty'      => $v->managing_stock() ? (int) $v->get_stock_quantity() : null,
					'manage_stock'   => (bool) $v->managing_stock(),
					'attributes'     => $v->get_attributes(),
					'image_id'       => (int) $v->get_image_id() ?: null,
				];
			}
		}

		$attrs = [];
		foreach ( $wc->get_attributes() as $key => $attr ) {
			$attrs[] = [
				'name'                => is_object( $attr ) ? $attr->get_name() : (string) $key,
				'options'             => is_object( $attr ) ? $attr->get_options() : [],
				'used_for_variations' => is_object( $attr ) ? $attr->get_variation() : false,
				'visible'             => is_object( $attr ) ? $attr->get_visible() : true,
			];
		}

		$primaryId = (int) $wc->get_image_id();
		return [
			'id'               => $wc->get_id(),
			'source'           => 'woo',
			'title'            => $wc->get_name(),
			'slug'             => $wc->get_slug(),
			'status'           => $wc->get_status(),
			'type'             => $wc->get_type(),
			'description'      => $wc->get_description(),
			'short_description'=> $wc->get_short_description(),
			'price' => [
				'regular' => $this->wcPriceCents( $wc->get_regular_price() ),
				'sale'    => $this->wcPriceCents( $wc->get_sale_price() ),
				'sale_from' => $wc->get_date_on_sale_from() ? $wc->get_date_on_sale_from()->getTimestamp() : null,
				'sale_to'   => $wc->get_date_on_sale_to()   ? $wc->get_date_on_sale_to()->getTimestamp()   : null,
				'cost'      => null, // Woo core doesn't track cost
			],
			'inventory' => [
				'sku'          => (string) $wc->get_sku(),
				'manage_stock' => (bool) $wc->managing_stock(),
				'stock_qty'    => $wc->managing_stock() ? (int) $wc->get_stock_quantity() : null,
				'stock_status' => $wc->get_stock_status(),
				'backorder'    => $wc->get_backorders(),
				'low_stock_amount' => $wc->get_low_stock_amount() !== '' ? (int) $wc->get_low_stock_amount() : null,
			],
			'shipping' => [
				'weight'         => (string) $wc->get_weight(),
				'length'         => (string) $wc->get_length(),
				'width'          => (string) $wc->get_width(),
				'height'         => (string) $wc->get_height(),
				'shipping_class' => (string) $wc->get_shipping_class(),
				'virtual'        => (bool) $wc->is_virtual(),
				'downloadable'   => (bool) $wc->is_downloadable(),
			],
			'images' => [
				'primary' => $primaryId ? [
					'id'  => $primaryId,
					'url' => (string) wp_get_attachment_image_url( $primaryId, 'medium' ),
				] : null,
				'gallery' => $gallery,
			],
			'variants'   => $variants,
			'attributes' => $attrs,
			'seo' => [
				// Standard meta keys most SEO plugins write; harmless when blank
				'meta_title'       => (string) get_post_meta( $wc->get_id(), '_yoast_wpseo_title', true ),
				'meta_description' => (string) get_post_meta( $wc->get_id(), '_yoast_wpseo_metadesc', true ),
			],
			'urls' => [
				'view'         => (string) $wc->get_permalink(),
				'edit_in_woo'  => (string) get_edit_post_link( $wc->get_id(), 'raw' ),
			],
		];
	}

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
				'gallery' => [],
			],
			'variants'   => [], // populated via /admin/products/{id}/variants in a follow-up
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
			return $this->patchWooProduct( $id, $body );
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
			return $this->bulkWooProducts( $action, $ids, $body );
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
			   LIMIT :per OFFSET :offset"
		);
		$stmt->bindValue( ':per', $per, \PDO::PARAM_INT );
		$stmt->bindValue( ':offset', $offset, \PDO::PARAM_INT );
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
