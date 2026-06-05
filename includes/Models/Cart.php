<?php
/**
 * Shop by Therum — Cart DTO.
 *
 * Read view of a session row in 'cart' or 'checkout' status, plus its items.
 * Returned by CartService::find(). Immutable — mutations go through
 * CartService methods, which return a fresh Cart.
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Cart {

	public function __construct(
		public readonly int $id,
		public readonly string $token,
		public readonly ?int $userId,
		public readonly ?string $email,
		public readonly string $currency,
		public readonly string $status,

		public readonly Money $subtotal,
		public readonly Money $shippingTotal,
		public readonly Money $taxTotal,
		public readonly Money $discountTotal,
		public readonly Money $grandTotal,

		/** @var array<string,mixed>|null */
		public readonly ?array $shipAddress,
		/** @var array<string,mixed>|null */
		public readonly ?array $billAddress,

		public readonly ?string $shippingMethod,
		public readonly ?string $shippingProvider,
		public readonly ?string $paymentMethod,
		public readonly ?string $paymentProvider,
		public readonly ?string $paymentIntentId,

		/** @var CartItem[] */
		public readonly array $items,

		/** @var array<string,mixed> */
		public readonly array $meta,

		public readonly int $createdAt,
		public readonly int $updatedAt,
		public readonly ?int $expiresAt,
	) {}

	/**
	 * @param array<string,mixed> $row
	 * @param CartItem[]          $items
	 */
	public static function fromRow( array $row, array $items ): self {
		$currency = (string) ( $row['currency'] ?? 'USD' );
		return new self(
			id:                (int) $row['id'],
			token:             (string) $row['token'],
			userId:            isset( $row['user_id'] ) ? (int) $row['user_id'] : null,
			email:             $row['email'] ?? null,
			currency:          $currency,
			status:            (string) $row['status'],
			subtotal:          Money::cents( (int) ( $row['subtotal']       ?? 0 ), $currency ),
			shippingTotal:     Money::cents( (int) ( $row['shipping_total'] ?? 0 ), $currency ),
			taxTotal:          Money::cents( (int) ( $row['tax_total']      ?? 0 ), $currency ),
			discountTotal:     Money::cents( (int) ( $row['discount_total'] ?? 0 ), $currency ),
			grandTotal:        Money::cents( (int) ( $row['grand_total']    ?? 0 ), $currency ),
			shipAddress:       self::parseJsonObject( $row['ship_address']    ?? null ),
			billAddress:       self::parseJsonObject( $row['bill_address']    ?? null ),
			shippingMethod:    $row['shipping_method']   ?? null,
			shippingProvider:  $row['shipping_provider'] ?? null,
			paymentMethod:     $row['payment_method']    ?? null,
			paymentProvider:   $row['payment_provider']  ?? null,
			paymentIntentId:   $row['payment_intent_id'] ?? null,
			items:             $items,
			meta:              self::parseJsonObject( $row['meta'] ?? null ) ?? [],
			createdAt:         (int) $row['created_at'],
			updatedAt:         (int) $row['updated_at'],
			expiresAt:         isset( $row['expires_at'] ) ? (int) $row['expires_at'] : null,
		);
	}

	public function itemCount(): int {
		return array_sum( array_map( fn( CartItem $i ): int => $i->quantity, $this->items ) );
	}

	public function isEmpty(): bool {
		return count( $this->items ) === 0;
	}

	/** @return array<string,mixed>|null */
	private static function parseJsonObject( ?string $json ): ?array {
		if ( $json === null || $json === '' ) return null;
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
