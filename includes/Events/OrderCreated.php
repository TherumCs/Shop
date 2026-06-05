<?php
/**
 * Fired (sync) when an order row is first persisted, before payment.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class OrderCreated implements Event {

	public function __construct(
		public int $orderId,
		public string $orderNumber,
		public string $email,
		public int $grandTotalMinor,
		public string $currency,
	) {}

	public static function name(): string { return 'order.created'; }
}
