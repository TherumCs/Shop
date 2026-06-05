<?php
/**
 * Shop by Therum — Product DTO.
 *
 * Immutable read view of a `products` row. Returned by ProductRepository.
 * No magic, no lazy loading. If you need variants/images/attrs on this
 * product, call the appropriate repository.
 *
 * Capability flags drive UI and behavior. See has_variants/is_shippable/
 * is_digital/is_pod/track_inventory.
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Product {

	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly string $slug,
		public readonly string $title,
		public readonly ?string $description,
		public readonly ?string $shortDescription,
		public readonly string $status,
		public readonly ?int $authorId,
		public readonly int $createdAt,
		public readonly int $updatedAt,
		public readonly ?int $publishedAt,

		// Capability flags
		public readonly bool $hasVariants,
		public readonly bool $isShippable,
		public readonly bool $isDigital,
		public readonly bool $isPod,
		public readonly bool $trackInventory,

		// Product-level pricing (used only when hasVariants = false)
		public readonly ?Money $price,
		public readonly ?Money $compareAtPrice,
		public readonly ?Money $cost,
		public readonly ?string $sku,
		public readonly ?int $stockQty,

		// Shipping
		public readonly ?float $weight,
		public readonly ?float $length,
		public readonly ?float $width,
		public readonly ?float $height,
		public readonly string $weightUnit,
		public readonly string $dimensionUnit,

		// Media
		public readonly ?int $primaryImageId,
		/** @var int[] */
		public readonly array $galleryImageIds,

		/** @var array<string,mixed> */
		public readonly array $meta,
	) {}

	/**
	 * Hydrate from a raw DB row. Static so the repository can construct
	 * without exposing constructor noise.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function fromRow( array $row, string $currency = 'USD' ): self {
		return new self(
			id:                (int) $row['id'],
			uuid:              (string) $row['uuid'],
			slug:              (string) $row['slug'],
			title:             (string) $row['title'],
			description:       $row['description']       ?? null,
			shortDescription:  $row['short_description'] ?? null,
			status:            (string) $row['status'],
			authorId:          isset( $row['author_id'] ) ? (int) $row['author_id'] : null,
			createdAt:         (int) $row['created_at'],
			updatedAt:         (int) $row['updated_at'],
			publishedAt:       isset( $row['published_at'] ) ? (int) $row['published_at'] : null,

			hasVariants:       (bool) ( $row['has_variants']    ?? 0 ),
			isShippable:       (bool) ( $row['is_shippable']    ?? 1 ),
			isDigital:         (bool) ( $row['is_digital']      ?? 0 ),
			isPod:             (bool) ( $row['is_pod']          ?? 0 ),
			trackInventory:    (bool) ( $row['track_inventory'] ?? 0 ),

			price:             isset( $row['price'] )            ? Money::cents( (int) $row['price'],            $currency ) : null,
			compareAtPrice:    isset( $row['compare_at_price'] ) ? Money::cents( (int) $row['compare_at_price'], $currency ) : null,
			cost:              isset( $row['cost'] )             ? Money::cents( (int) $row['cost'],             $currency ) : null,
			sku:               $row['sku'] ?? null,
			stockQty:          isset( $row['stock_qty'] ) ? (int) $row['stock_qty'] : null,

			weight:            isset( $row['weight'] ) ? (float) $row['weight'] : null,
			length:            isset( $row['length'] ) ? (float) $row['length'] : null,
			width:             isset( $row['width'] )  ? (float) $row['width']  : null,
			height:            isset( $row['height'] ) ? (float) $row['height'] : null,
			weightUnit:        (string) ( $row['weight_unit']    ?? 'g'  ),
			dimensionUnit:     (string) ( $row['dimension_unit'] ?? 'cm' ),

			primaryImageId:    isset( $row['primary_image_id'] ) ? (int) $row['primary_image_id'] : null,
			galleryImageIds:   self::parseJsonArray( $row['gallery_image_ids'] ?? null ),

			meta:              self::parseJsonObject( $row['meta'] ?? null ),
		);
	}

	/**
	 * Check whether a customer can buy this product right now (top-level
	 * check; variant-level stock is checked separately on variant products).
	 */
	public function isPurchasable(): bool {
		if ( $this->status !== 'active' ) return false;
		if ( $this->hasVariants )         return true; // variant-level check elsewhere
		if ( ! $this->trackInventory )    return true;
		return ( $this->stockQty ?? 0 ) > 0;
	}

	/** @return int[] */
	private static function parseJsonArray( ?string $json ): array {
		if ( $json === null || $json === '' ) return [];
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? array_map( 'intval', $decoded ) : [];
	}

	/** @return array<string,mixed> */
	private static function parseJsonObject( ?string $json ): array {
		if ( $json === null || $json === '' ) return [];
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
