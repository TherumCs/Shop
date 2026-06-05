<?php
/**
 * Fired (sync) after a line's quantity changes.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class CartItemUpdated implements Event {

	public function __construct(
		public int $sessionId,
		public string $sessionToken,
		public int $itemId,
		public int $previousQuantity,
		public int $newQuantity,
	) {}

	public static function name(): string { return 'cart.item_updated'; }
}
