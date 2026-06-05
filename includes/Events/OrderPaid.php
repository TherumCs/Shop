<?php
/**
 * Fired (sync) when an order moves from `pending` → `processing` after a
 * PSP webhook confirms payment. The big payment-time event — fulfillment
 * routing, customer email, vendor handoff all subscribe to this.
 *
 * Subscribers should generally do the minimum sync work needed for
 * correctness and queue() heavier follow-ups (vendor API calls, email
 * sends) so the webhook handler returns 200 fast.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class OrderPaid implements Event {

	public function __construct(
		public int $orderId,
		public string $orderNumber,
		public string $email,
		public int $grandTotalMinor,
		public string $currency,
		public string $paymentProvider,
		public string $paymentIntentId,
	) {}

	public static function name(): string { return 'order.paid'; }
}
