<?php
/**
 * Fired (sync) after a line is successfully added to a cart.
 * Subscribers commonly: analytics, abandonment tracking, stock pre-reservation.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class CartItemAdded implements Event {

	public function __construct(
		public int $sessionId,
		public string $sessionToken,
		public int $productId,
		public ?int $variantId,
		public int $quantity,
	) {}

	public static function name(): string { return 'cart.item_added'; }
}
