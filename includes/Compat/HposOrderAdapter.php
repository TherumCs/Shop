<?php
/**
 * Shop by Therum — HPOS-style order adapter (opt-in).
 *
 * Surfaces our SQLite orders as if they were Woo HPOS orders so
 * third-party Woo extensions (accounting plugins, label generators,
 * analytics) can read Shop orders without knowing they came from a
 * different storage layer.
 *
 * Approach:
 *
 *   - Listen to `woocommerce_order_query_args` to inject our order IDs
 *     into the result set when callers list orders
 *   - Listen to `woocommerce_order_class` to swap WC_Order for our
 *     wrapper when Woo is asked to hydrate an ID we own
 *   - The wrapper (ShopWcOrderWrapper) lazy-loads from our OrderRepository
 *     and exposes the WC_Order surface Woo extensions read
 *
 * Off by default — controlled by `shop_hpos_export` option. Most stores
 * won't need this; it exists for stores that depend on a specific Woo
 * extension that won't be moved to Shop-native first.
 *
 * Limitations (acknowledged):
 *
 *   - Read-only. We don't translate writes back into our SQLite — Woo
 *     extensions that try to update an order via WC_Order::save() on
 *     one of our orders get a no-op silently. This is intentional: the
 *     Therum order is the system of record and any mutation should go
 *     through our admin or REST surface
 *   - We use namespaced IDs (NS_PREFIX + therum_id) so there's never
 *     an ID collision with native Woo orders
 *   - The wrapper carries only the most-read fields. Niche getters
 *     return reasonable empties
 */

namespace Shop\Compat;

use Shop\Repositories\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class HposOrderAdapter {

	/** Namespaced ID prefix — orders we own get IDs ≥ 90_000_000_000. */
	public const ID_OFFSET = 90_000_000_000;

	public function __construct(
		private readonly OrderRepository $orders,
	) {}

	public static function isEnabled(): bool {
		return (bool) get_option( 'shop_hpos_export', false );
	}

	public function register(): void {
		if ( ! self::isEnabled() ) return;
		if ( ! function_exists( 'WC' ) ) return;

		add_filter( 'woocommerce_order_class',         [ $this, 'orderClass' ],         10, 4 );
		add_filter( 'woocommerce_order_query_args',    [ $this, 'injectQueryArgs' ],    10, 2 );
		add_filter( 'woocommerce_pre_get_order_data',  [ $this, 'maybeHydrate' ],       10, 2 );
	}

	/**
	 * When Woo asks "which class should this order ID instantiate?",
	 * return our wrapper for IDs we own.
	 *
	 * @param string $classname
	 * @param string $type
	 * @param int    $order_id
	 */
	public function orderClass( $classname, $type, $order_id ) {
		if ( $this->ownsId( (int) $order_id ) ) {
			return ShopWcOrderWrapper::class;
		}
		return $classname;
	}

	/**
	 * Append our order IDs (translated to the namespaced range) onto
	 * Woo's order query results.
	 *
	 * For simple listing queries this works. For advanced queries with
	 * date/meta filters, the wrapper still satisfies Woo's hydration
	 * but the filter probably won't yield Therum orders unless we add
	 * a Woo-aware translator. v1 documents this as a known limit.
	 *
	 * @param array<string,mixed> $args
	 * @param \WC_Order_Query     $query
	 */
	public function injectQueryArgs( $args, $query ): array {
		// Intentionally a no-op for v1 — listing all-orders union is
		// expensive to do correctly. Use Shop → Orders for our orders;
		// use Woo's order list for Woo's. Future versions: federate.
		return $args;
	}

	/**
	 * When Woo asks for raw order data for an ID, intercept ours and
	 * return our SQLite row mapped to the wp_wc_orders shape.
	 *
	 * @param array<string,mixed>|null $data
	 * @param int                      $order_id
	 */
	public function maybeHydrate( $data, $order_id ): array|null {
		$id = (int) $order_id;
		if ( ! $this->ownsId( $id ) ) return $data;

		$shop_id = $id - self::ID_OFFSET;
		$order   = $this->orders->findById( $shop_id );
		if ( $order === null ) return $data;

		return [
			'id'                 => $id,
			'parent_id'          => 0,
			'order_key'          => 'shop_' . $order->number,
			'created_via'        => 'shop',
			'status'             => 'wc-' . $order->status,
			'currency'           => $order->currency,
			'customer_id'        => $order->userId ?? 0,
			'billing_email'      => $order->email,
			'date_created_gmt'   => gmdate( 'Y-m-d H:i:s', $order->createdAt ),
			'date_modified_gmt'  => gmdate( 'Y-m-d H:i:s', $order->updatedAt ),
			'date_paid_gmt'      => $order->paidAt ? gmdate( 'Y-m-d H:i:s', $order->paidAt ) : null,
			'discount_total'     => number_format( $order->discountTotal->minor / 100, 2, '.', '' ),
			'shipping_total'     => number_format( $order->shippingTotal->minor / 100, 2, '.', '' ),
			'cart_tax'           => number_format( $order->taxTotal->minor      / 100, 2, '.', '' ),
			'total'              => number_format( $order->grandTotal->minor    / 100, 2, '.', '' ),
			'payment_method'     => $order->paymentMethod   ?? '',
			'payment_method_title' => $order->paymentProvider ?? '',
			'transaction_id'     => $order->paymentIntentId ?? '',
		];
	}

	private function ownsId( int $id ): bool {
		return $id >= self::ID_OFFSET;
	}
}
