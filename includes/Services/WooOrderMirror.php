<?php
/**
 * Shop by Therum — WooOrderMirror.
 *
 * Subscribes to OrderPaid. When `shop_product_source = woo` is active,
 * mirrors the just-paid Therum order into a WC_Order so the Printful /
 * Printify / PodPartner / TapStitch / PodPluser Woo plugins pick it up
 * and trigger their existing fulfillment pipelines unchanged.
 *
 * The Therum order is the system of record; the WC_Order is a downstream
 * mirror. We persist the wc_order_id on the Therum order's meta for the
 * back-link, so refunds and status sync know where to point.
 *
 * Skipped when:
 *   - Woo isn't active
 *   - `shop_product_source` is `native` (no POD plugins to feed)
 *   - The Therum order has no Woo-sourced line items
 *
 * Failure mode: if WC_Order creation fails, we DO NOT roll back the
 * Therum order — the customer's payment already cleared. We log an
 * admin notice and keep going. Operators can retry the mirror via
 * `wp shop:mirror-order <number>`.
 */

namespace Shop\Services;

use Shop\Events\OrderPaid;
use Shop\Models\Order;
use Shop\Repositories\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class WooOrderMirror {

	public function __construct(
		private readonly OrderRepository $orders,
	) {}

	/**
	 * EventBus subscriber. Wire via Container in shop.php:
	 *   $bus->on( OrderPaid::class, [ $mirror, 'handle' ] );
	 */
	public function handle( OrderPaid $event ): void {
		if ( ! $this->shouldMirror() ) return;

		$order = $this->orders->findById( $event->orderId, $event->currency );
		if ( $order === null ) return;

		try {
			$wc_order_id = $this->mirror( $order );
			$this->orders->note(
				orderId:      $order->id,
				content:      sprintf( 'Mirrored to WooCommerce as order #%d (POD plugins notified).', $wc_order_id ),
				isSystemNote: true,
			);
		} catch ( \Throwable $e ) {
			$this->orders->note(
				orderId:      $order->id,
				content:      sprintf( 'WooCommerce mirror failed: %s', $e->getMessage() ),
				isSystemNote: true,
			);
			error_log( 'Shop\WooOrderMirror failed for order ' . $order->number . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Create a matching WC_Order with line items pointing at the Woo
	 * product / variation IDs. Returns the WC order ID.
	 */
	private function mirror( Order $order ): int {
		$wc_order = wc_create_order( [
			'customer_id'   => $order->userId ?? 0,
			'customer_note' => null,
			'created_via'   => 'shop-by-therum',
			'status'        => 'wc-processing',
		] );

		if ( is_wp_error( $wc_order ) ) {
			throw new \RuntimeException( $wc_order->get_error_message() );
		}

		// Address
		if ( is_array( $order->shipAddress ) ) {
			$wc_order->set_address( $this->mapAddress( $order->shipAddress ), 'shipping' );
		}
		if ( is_array( $order->billAddress ) ) {
			$wc_order->set_address( $this->mapAddress( $order->billAddress ), 'billing' );
		} elseif ( is_array( $order->shipAddress ) ) {
			$wc_order->set_address( $this->mapAddress( $order->shipAddress ), 'billing' );
		}
		$wc_order->set_billing_email( $order->email );

		// Line items — product_id is the Woo post ID, variation_id is the Woo variation ID.
		// Both come from the Therum order item directly (we set them when adding to cart).
		foreach ( $order->items as $item ) {
			$wc_product_id = $item->productId;
			$wc_variant_id = $item->variantId;

			$wc_product = $wc_product_id ? wc_get_product( $wc_variant_id ?? $wc_product_id ) : null;
			if ( ! $wc_product instanceof \WC_Product ) continue;

			$line_item_id = $wc_order->add_product( $wc_product, $item->quantity, [
				'subtotal' => (float) ( $item->lineTotal->minor / 100 ),
				'total'    => (float) ( $item->lineTotal->minor / 100 ),
			] );

			// Stash the Therum item id on the Woo line item so refund sync
			// can correlate back without ambiguity.
			if ( $line_item_id && method_exists( $wc_order, 'get_item' ) ) {
				$line = $wc_order->get_item( $line_item_id );
				if ( $line ) {
					$line->add_meta_data( '_shop_item_id', $item->id, true );
					$line->save();
				}
			}
		}

		// Totals
		$wc_order->set_shipping_total( (float) ( $order->shippingTotal->minor / 100 ) );
		$wc_order->set_total( (float) ( $order->grandTotal->minor / 100 ) );

		// Payment metadata
		$wc_order->set_payment_method( 'shop_psp' );
		$wc_order->set_payment_method_title( $order->paymentProvider ?? 'Shop PSP' );
		$wc_order->set_transaction_id( (string) ( $order->paymentIntentId ?? '' ) );

		// Provenance — link the two records to each other.
		$wc_order->add_meta_data( '_shop_order_id',     $order->id,     true );
		$wc_order->add_meta_data( '_shop_order_number', $order->number, true );
		$wc_order->add_order_note( sprintf( 'Mirrored from Shop by Therum order %s.', $order->number ) );
		$wc_order->save();

		return (int) $wc_order->get_id();
	}

	/**
	 * Translate Therum's loose address shape into Woo's known fields.
	 *
	 * @param array<string,mixed> $a
	 * @return array<string,string>
	 */
	private function mapAddress( array $a ): array {
		return [
			'first_name' => (string) ( $a['first_name'] ?? $a['firstName'] ?? '' ),
			'last_name'  => (string) ( $a['last_name']  ?? $a['lastName']  ?? '' ),
			'company'    => (string) ( $a['company']    ?? '' ),
			'address_1'  => (string) ( $a['address_1']  ?? $a['line1'] ?? '' ),
			'address_2'  => (string) ( $a['address_2']  ?? $a['line2'] ?? '' ),
			'city'       => (string) ( $a['city']       ?? '' ),
			'state'      => (string) ( $a['state']      ?? '' ),
			'postcode'   => (string) ( $a['postcode']   ?? $a['zip']   ?? '' ),
			'country'    => (string) ( $a['country']    ?? 'US' ),
			'phone'      => (string) ( $a['phone']      ?? '' ),
		];
	}

	private function shouldMirror(): bool {
		if ( ! function_exists( 'wc_create_order' ) ) return false;
		$source = (string) get_option( 'shop_product_source', 'native' );
		return $source === 'woo';
	}
}
