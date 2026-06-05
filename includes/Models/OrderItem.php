<?php
/**
 * Shop by Therum — OrderItem DTO.
 *
 * Read view of an `order_items` row. Carries the product snapshot taken at
 * order time so order history doesn't break when products are edited/deleted.
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderItem {

	public function __construct(
		public readonly int $id,
		public readonly int $orderId,
		public readonly ?int $shipmentId,
		public readonly ?int $productId,
		public readonly ?int $variantId,
		public readonly ?string $sku,
		public readonly string $title,
		/** @var array<string,mixed> */
		public readonly array $snapshot,
		public readonly int $quantity,
		public readonly Money $unitPrice,
		public readonly Money $lineTotal,
		public readonly Money $discountTotal,
		public readonly ?Money $vendorUnitCost,
		public readonly string $fulfillmentStatus,
		public readonly ?string $fulfillmentProvider,
		public readonly ?string $fulfillmentId,
		public readonly ?string $trackingNumber,
		public readonly ?string $trackingCarrier,
		public readonly ?int $fulfilledAt,
	) {}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function fromRow( array $row, string $currency = 'USD' ): self {
		return new self(
			id:                  (int) $row['id'],
			orderId:             (int) $row['order_id'],
			shipmentId:          isset( $row['shipment_id'] ) ? (int) $row['shipment_id'] : null,
			productId:           isset( $row['product_id'] ) ? (int) $row['product_id'] : null,
			variantId:           isset( $row['variant_id'] ) ? (int) $row['variant_id'] : null,
			sku:                 $row['sku'] ?? null,
			title:               (string) $row['title'],
			snapshot:            self::parseJsonObject( $row['product_snapshot'] ?? null ),
			quantity:            (int) $row['quantity'],
			unitPrice:           Money::cents( (int) $row['unit_price'], $currency ),
			lineTotal:           Money::cents( (int) $row['line_total'], $currency ),
			discountTotal:       Money::cents( (int) ( $row['discount_total'] ?? 0 ), $currency ),
			vendorUnitCost:      isset( $row['vendor_unit_cost'] )
				? Money::cents( (int) $row['vendor_unit_cost'], $currency )
				: null,
			fulfillmentStatus:   (string) ( $row['fulfillment_status'] ?? 'pending' ),
			fulfillmentProvider: $row['fulfillment_provider'] ?? null,
			fulfillmentId:       $row['fulfillment_id']       ?? null,
			trackingNumber:      $row['tracking_number']      ?? null,
			trackingCarrier:     $row['tracking_carrier']     ?? null,
			fulfilledAt:         isset( $row['fulfilled_at'] ) ? (int) $row['fulfilled_at'] : null,
		);
	}

	/** @return array<string,mixed> */
	private static function parseJsonObject( ?string $json ): array {
		if ( $json === null || $json === '' ) return [];
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
