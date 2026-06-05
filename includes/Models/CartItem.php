<?php
/**
 * Shop by Therum — CartItem DTO.
 *
 * One line in a Cart. Mirrors a `session_items` row with the prices
 * hydrated as Money.
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartItem {

	public function __construct(
		public readonly int $id,
		public readonly int $sessionId,
		public readonly int $productId,
		public readonly ?int $variantId,
		public readonly int $quantity,
		public readonly Money $unitPrice,
		public readonly Money $lineTotal,
		/** @var array<string,mixed> */
		public readonly array $meta,
	) {}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function fromRow( array $row, string $currency = 'USD' ): self {
		return new self(
			id:         (int) $row['id'],
			sessionId:  (int) $row['session_id'],
			productId:  (int) $row['product_id'],
			variantId:  isset( $row['variant_id'] ) ? (int) $row['variant_id'] : null,
			quantity:   (int) $row['quantity'],
			unitPrice:  Money::cents( (int) $row['unit_price'], $currency ),
			lineTotal:  Money::cents( (int) $row['line_total'], $currency ),
			meta:       self::parseJsonObject( $row['meta'] ?? null ),
		);
	}

	/** @return array<string,mixed> */
	private static function parseJsonObject( ?string $json ): array {
		if ( $json === null || $json === '' ) return [];
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
