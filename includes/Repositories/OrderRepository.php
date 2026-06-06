<?php
/**
 * Shop by Therum — OrderRepository.
 *
 * Owns the SQL for orders, order_items, order_shipments. OrderService is the
 * only caller in v1. Webhook handler reaches in via findByPaymentIntent() to
 * resolve a PSP event to an order.
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\Cart;
use Shop\Models\Order;
use Shop\Models\OrderItem;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderRepository {

	public function __construct(
		private readonly ProductRepository $products,
	) {}

	public function findById( int $id, string $currency = 'USD' ): ?Order {
		$row = DB::pdo()->prepare( "SELECT * FROM orders WHERE id = :i" );
		$row->execute( [ ':i' => $id ] );
		$o = $row->fetch();
		if ( ! $o ) return null;
		return $this->hydrate( $o );
	}

	public function findByNumber( string $number, string $currency = 'USD' ): ?Order {
		$row = DB::pdo()->prepare( "SELECT * FROM orders WHERE number = :n" );
		$row->execute( [ ':n' => $number ] );
		$o = $row->fetch();
		if ( ! $o ) return null;
		return $this->hydrate( $o );
	}

	public function findByPaymentIntent( string $intentId ): ?Order {
		$row = DB::pdo()->prepare( "SELECT * FROM orders WHERE payment_intent_id = :i" );
		$row->execute( [ ':i' => $intentId ] );
		$o = $row->fetch();
		if ( ! $o ) return null;
		return $this->hydrate( $o );
	}

	/**
	 * Paginated list with optional status / date filters. Used by the
	 * order exporter, the admin orders grid, and the HPOS adapter.
	 *
	 * Filters (all optional):
	 *   status     — exact match on orders.status
	 *   date_from  — ISO 8601 string; orders created at or after
	 *   date_to    — ISO 8601 string; orders created at or before
	 *
	 * @param array<string,mixed> $filters
	 * @return Order[]
	 */
	public function list( array $filters = [], int $limit = 100, int $offset = 0 ): array {
		$where = '1=1';
		$bind  = [];
		if ( ! empty( $filters['status'] ) ) {
			$where .= ' AND status = :st';
			$bind[':st'] = (string) $filters['status'];
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$ts = strtotime( (string) $filters['date_from'] );
			if ( $ts !== false ) { $where .= ' AND created_at >= :df'; $bind[':df'] = $ts; }
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$ts = strtotime( (string) $filters['date_to'] );
			if ( $ts !== false ) { $where .= ' AND created_at <= :dt'; $bind[':dt'] = $ts; }
		}
		$stmt = DB::pdo()->prepare(
			"SELECT * FROM orders WHERE $where ORDER BY created_at DESC LIMIT :lim OFFSET :off"
		);
		foreach ( $bind as $k => $v ) $stmt->bindValue( $k, $v );
		$stmt->bindValue( ':lim', $limit,  \PDO::PARAM_INT );
		$stmt->bindValue( ':off', $offset, \PDO::PARAM_INT );
		$stmt->execute();
		$rows = $stmt->fetchAll();
		return array_map( fn( $r ) => $this->hydrate( $r ), $rows );
	}

	/**
	 * Create an order from a cart. Snapshots every line item so historical
	 * data survives product edits. Returns the new Order.
	 */
	public function createFromCart( Cart $cart, string $email ): Order {
		$pdo = DB::pdo();

		$pdo->prepare(
			"INSERT INTO orders (
				number, session_id, user_id, email, currency, status,
				subtotal, shipping_total, tax_total, discount_total, grand_total,
				ship_address, bill_address,
				created_at, updated_at
			) VALUES (
				:n, :sid, :uid, :em, :cur, 'pending',
				:sub, :sh, :tx, :dc, :gt,
				:ship, :bill,
				unixepoch(), unixepoch()
			)"
		)->execute( [
			':n'    => self::generateNumber(),
			':sid'  => $cart->id,
			':uid'  => $cart->userId,
			':em'   => $email,
			':cur'  => $cart->currency,
			':sub'  => $cart->subtotal->minor,
			':sh'   => $cart->shippingTotal->minor,
			':tx'   => $cart->taxTotal->minor,
			':dc'   => $cart->discountTotal->minor,
			':gt'   => $cart->grandTotal->minor,
			':ship' => $cart->shipAddress !== null ? wp_json_encode( $cart->shipAddress ) : null,
			':bill' => $cart->billAddress !== null ? wp_json_encode( $cart->billAddress ) : null,
		] );

		$orderId = (int) $pdo->lastInsertId();

		// ─── POD routing: group lines by vendor into shipments ──────────────
		// Each unique pod_provider in the cart becomes one order_shipment.
		// Local-stock or unrouted lines collect under the 'local' shipment.
		// Per-vendor shipping + tax quotes land here when ShippingStep /
		// TaxStep wire to real connectors (Phase 1+); v1 leaves them zero.
		$shipment_ids = []; // pod_provider => shipment_id
		$shipmentStmt = $pdo->prepare(
			"INSERT INTO order_shipments (
				order_id, pod_provider, shipping_total, tax_total, tax_source, status, ship_address
			) VALUES (
				:oid, :pp, 0, 0, 'fallback_rate', 'pending', :addr
			)"
		);

		// Snapshot each cart line into order_items. Look up the product for
		// title + a small JSON snapshot.
		$insertItem = $pdo->prepare(
			"INSERT INTO order_items (
				order_id, shipment_id, product_id, variant_id, sku, title,
				product_snapshot, quantity, unit_price, line_total,
				fulfillment_provider
			) VALUES (
				:oid, :ship, :pid, :vid, :sku, :title,
				:snap, :qty, :up, :lt,
				:fp
			)"
		);

		foreach ( $cart->items as $line ) {
			$product = $this->products->findById( $line->productId, $cart->currency );
			$variant = $line->variantId !== null
				? $this->products->findVariant( $line->variantId, $cart->currency )
				: null;

			$title = $product?->title ?? 'Unknown product';
			$sku   = $variant?->sku ?? $product?->sku;
			$vendor = $variant?->podProvider ?? 'local';

			// Lazy-create the shipment for this vendor on first sight.
			if ( ! isset( $shipment_ids[ $vendor ] ) ) {
				$shipmentStmt->execute( [
					':oid'  => $orderId,
					':pp'   => $vendor === 'local' ? null : $vendor,
					':addr' => $cart->shipAddress !== null ? wp_json_encode( $cart->shipAddress ) : null,
				] );
				$shipment_ids[ $vendor ] = (int) $pdo->lastInsertId();
			}

			$snapshot = [
				'product_id'  => $line->productId,
				'variant_id'  => $line->variantId,
				'title'       => $title,
				'sku'         => $sku,
				'unit_price'  => $line->unitPrice->minor,
				'currency'    => $cart->currency,
				'options'     => $variant?->meta['options'] ?? null,
				'is_pod'      => $product?->isPod ?? false,
				'pod_provider'=> $variant?->podProvider,
				'pod_product_id' => $variant?->podProductId,
				'pod_variant_id' => $variant?->podVariantId,
			];

			$insertItem->execute( [
				':oid'   => $orderId,
				':ship'  => $shipment_ids[ $vendor ],
				':pid'   => $line->productId,
				':vid'   => $line->variantId,
				':sku'   => $sku,
				':title' => $title,
				':snap'  => wp_json_encode( $snapshot ),
				':qty'   => $line->quantity,
				':up'    => $line->unitPrice->minor,
				':lt'    => $line->lineTotal->minor,
				':fp'    => $vendor === 'local' ? null : $vendor,
			] );
		}

		$order = $this->findById( $orderId, $cart->currency );
		if ( $order === null ) {
			throw new \RuntimeException( 'OrderRepository::createFromCart — could not re-fetch just-inserted order' );
		}
		return $order;
	}

	public function setPaymentIntent( int $orderId, string $provider, string $intentId, ?string $method = null ): void {
		DB::pdo()->prepare(
			"UPDATE orders
			    SET payment_provider  = :prov,
			        payment_intent_id = :intent,
			        payment_method    = COALESCE(:method, payment_method),
			        updated_at        = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':prov'   => $provider,
			':intent' => $intentId,
			':method' => $method,
			':i'      => $orderId,
		] );
	}

	public function setStatus( int $orderId, string $status ): void {
		DB::pdo()->prepare(
			"UPDATE orders
			    SET status = :s,
			        paid_at = CASE WHEN :s = 'processing' AND paid_at IS NULL THEN unixepoch() ELSE paid_at END,
			        updated_at = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':s' => $status,
			':i' => $orderId,
		] );
	}

	public function itemsFor( int $orderId, string $currency = 'USD' ): array {
		$stmt = DB::pdo()->prepare( "SELECT * FROM order_items WHERE order_id = :o ORDER BY id ASC" );
		$stmt->execute( [ ':o' => $orderId ] );
		return array_map(
			fn( array $r ): OrderItem => OrderItem::fromRow( $r, $currency ),
			$stmt->fetchAll()
		);
	}

	/**
	 * Append a note to the order's audit log.
	 */
	public function note( int $orderId, string $content, bool $isSystemNote = true, bool $isCustomerNote = false, ?int $authorId = null, ?string $authorName = null ): void {
		DB::pdo()->prepare(
			"INSERT INTO order_notes (order_id, author_id, author_name, is_customer_note, is_system_note, content)
			 VALUES (:o, :aid, :aname, :cust, :sys, :c)"
		)->execute( [
			':o'     => $orderId,
			':aid'   => $authorId,
			':aname' => $authorName,
			':cust'  => $isCustomerNote ? 1 : 0,
			':sys'   => $isSystemNote   ? 1 : 0,
			':c'     => $content,
		] );
	}

	/**
	 * Generate a human-readable order number. Format: SH-YYYYMMDD-XXXXXX
	 * where XXXXXX is 6 random hex chars. Uniqueness enforced by DB UNIQUE.
	 */
	private static function generateNumber(): string {
		return sprintf(
			'SH-%s-%s',
			gmdate( 'Ymd' ),
			strtoupper( bin2hex( random_bytes( 3 ) ) )
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function hydrate( array $row ): Order {
		$items = $this->itemsFor( (int) $row['id'], (string) $row['currency'] );
		return Order::fromRow( $row, $items );
	}
}
