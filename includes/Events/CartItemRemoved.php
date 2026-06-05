<?php
/**
 * Fired (sync) after a line is removed from a cart.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class CartItemRemoved implements Event {

	public function __construct(
		public int $sessionId,
		public string $sessionToken,
		public int $itemId,
		public int $productId,
		public ?int $variantId,
		public int $quantity,
	) {}

	public static function name(): string { return 'cart.item_removed'; }
}
