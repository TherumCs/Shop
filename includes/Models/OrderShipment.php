<?php
/**
 * Shop by Therum — OrderShipment DTO.
 *
 * One sub-shipment of an order, scoped to a vendor. Multi-vendor orders
 * carry multiple shipments — each one routes independently. Carries its
 * own shipping + tax quotes (vendors quote separately), tracking, and
 * status timeline (pending → shipped → delivered).
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderShipment {

	public function __construct(
		public readonly int $id,
		public readonly int $orderId,
		public readonly ?string $podProvider,
		public readonly ?string $shippingProvider,
		public readonly ?string $shippingMethod,
		public readonly Money $shippingTotal,
		public readonly Money $taxTotal,
		public readonly string $taxSource,
		public readonly ?string $quoteRef,
		public readonly ?int $quotedAt,
		/** @var array<string,mixed>|null */
		public readonly ?array $shipAddress,
		public readonly ?string $trackingNumber,
		public readonly ?string $trackingCarrier,
		public readonly string $status,
		public readonly ?int $shippedAt,
		public readonly ?int $deliveredAt,
		public readonly int $createdAt,
		public readonly int $updatedAt,
	) {}

	/** @param array<string,mixed> $row */
	public static function fromRow( array $row, string $currency = 'USD' ): self {
		return new self(
			id:               (int) $row['id'],
			orderId:          (int) $row['order_id'],
			podProvider:      $row['pod_provider']      ?? null,
			shippingProvider: $row['shipping_provider'] ?? null,
			shippingMethod:   $row['shipping_method']   ?? null,
			shippingTotal:    Money::cents( (int) ( $row['shipping_total'] ?? 0 ), $currency ),
			taxTotal:         Money::cents( (int) ( $row['tax_total']      ?? 0 ), $currency ),
			taxSource:        (string) ( $row['tax_source'] ?? 'fallback_rate' ),
			quoteRef:         $row['quote_ref'] ?? null,
			quotedAt:         isset( $row['quoted_at'] ) ? (int) $row['quoted_at'] : null,
			shipAddress:      self::parseJson( $row['ship_address'] ?? null ),
			trackingNumber:   $row['tracking_number']  ?? null,
			trackingCarrier:  $row['tracking_carrier'] ?? null,
			status:           (string) ( $row['status'] ?? 'pending' ),
			shippedAt:        isset( $row['shipped_at'] )   ? (int) $row['shipped_at']   : null,
			deliveredAt:      isset( $row['delivered_at'] ) ? (int) $row['delivered_at'] : null,
			createdAt:        (int) $row['created_at'],
			updatedAt:        (int) $row['updated_at'],
		);
	}

	/** @return array<string,mixed>|null */
	private static function parseJson( ?string $json ): ?array {
		if ( $json === null || $json === '' ) return null;
		$d = json_decode( $json, true );
		return is_array( $d ) ? $d : null;
	}
}
