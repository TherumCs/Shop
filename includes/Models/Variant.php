<?php
/**
 * Shop by Therum — Variant DTO.
 *
 * Immutable read view of a `product_variants` row. POD routing fields
 * (podProvider, podProductId, podVariantId) are the per-variant vendor
 * link — the mechanism that lets one customer-facing product source
 * variants from different fulfillment vendors.
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Variant {

	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly int $productId,
		public readonly ?string $sku,
		public readonly int $position,
		public readonly bool $enabled,

		public readonly ?Money $price,
		public readonly ?Money $compareAtPrice,
		public readonly ?Money $cost,
		public readonly ?int $stockQty,

		public readonly ?float $weight,
		public readonly ?float $length,
		public readonly ?float $width,
		public readonly ?float $height,

		public readonly ?int $imageId,

		// POD routing — present iff parent product is_pod = 1
		public readonly ?string $podProvider,
		public readonly ?string $podProductId,
		public readonly ?string $podVariantId,

		/** @var array<string,mixed> */
		public readonly array $meta,
	) {}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function fromRow( array $row, string $currency = 'USD' ): self {
		return new self(
			id:              (int) $row['id'],
			uuid:            (string) $row['uuid'],
			productId:       (int) $row['product_id'],
			sku:             $row['sku'] ?? null,
			position:        (int) ( $row['position'] ?? 0 ),
			enabled:         (bool) ( $row['enabled'] ?? 1 ),

			price:           isset( $row['price'] )            ? Money::cents( (int) $row['price'],            $currency ) : null,
			compareAtPrice:  isset( $row['compare_at_price'] ) ? Money::cents( (int) $row['compare_at_price'], $currency ) : null,
			cost:            isset( $row['cost'] )             ? Money::cents( (int) $row['cost'],             $currency ) : null,
			stockQty:        isset( $row['stock_qty'] ) ? (int) $row['stock_qty'] : null,

			weight:          isset( $row['weight'] ) ? (float) $row['weight'] : null,
			length:          isset( $row['length'] ) ? (float) $row['length'] : null,
			width:           isset( $row['width'] )  ? (float) $row['width']  : null,
			height:          isset( $row['height'] ) ? (float) $row['height'] : null,

			imageId:         isset( $row['image_id'] ) ? (int) $row['image_id'] : null,

			podProvider:     $row['pod_provider']    ?? null,
			podProductId:    $row['pod_product_id']  ?? null,
			podVariantId:    $row['pod_variant_id']  ?? null,

			meta:            self::parseJsonObject( $row['meta'] ?? null ),
		);
	}

	/** @return array<string,mixed> */
	private static function parseJsonObject( ?string $json ): array {
		if ( $json === null || $json === '' ) return [];
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
