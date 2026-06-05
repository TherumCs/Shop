<?php
/**
 * Shop by Therum — Coupon DTO.
 *
 * Immutable read view of a `coupons` row. The discount_type / scope /
 * scope_ref triplet defines what gets discounted; the rest controls
 * when it can be used.
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Coupon {

	public const TYPE_PERCENT        = 'percent';
	public const TYPE_FIXED_CART     = 'fixed_cart';
	public const TYPE_FIXED_PRODUCT  = 'fixed_product';
	public const TYPE_FREE_SHIPPING  = 'free_shipping';

	public const SCOPE_CART     = 'cart';
	public const SCOPE_PRODUCT  = 'product';
	public const SCOPE_VARIANT  = 'variant';
	public const SCOPE_VENDOR   = 'vendor';

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_PAUSED  = 'paused';
	public const STATUS_EXPIRED = 'expired';

	public function __construct(
		public readonly int $id,
		public readonly ?string $code,
		public readonly string $discountType,
		public readonly int $amount,             // percent (0-100) or cents
		public readonly string $scope,
		public readonly ?string $scopeRef,
		public readonly ?Money $minimumAmount,
		public readonly ?Money $maximumAmount,
		public readonly ?int $usageLimit,
		public readonly ?int $usageLimitPerUser,
		public readonly int $usageCount,
		public readonly bool $individualUse,
		public readonly ?int $dateStarts,
		public readonly ?int $dateExpires,
		public readonly string $status,
		public readonly ?string $description,
	) {}

	/** @param array<string,mixed> $row */
	public static function fromRow( array $row, string $currency = 'USD' ): self {
		return new self(
			id:                 (int) $row['id'],
			code:               $row['code'] ?? null,
			discountType:       (string) $row['discount_type'],
			amount:             (int) $row['amount'],
			scope:              (string) ( $row['scope'] ?? self::SCOPE_CART ),
			scopeRef:           $row['scope_ref'] ?? null,
			minimumAmount:      isset( $row['minimum_amount'] ) ? Money::cents( (int) $row['minimum_amount'], $currency ) : null,
			maximumAmount:      isset( $row['maximum_amount'] ) ? Money::cents( (int) $row['maximum_amount'], $currency ) : null,
			usageLimit:         isset( $row['usage_limit'] )           ? (int) $row['usage_limit']           : null,
			usageLimitPerUser:  isset( $row['usage_limit_per_user'] )  ? (int) $row['usage_limit_per_user']  : null,
			usageCount:         (int) ( $row['usage_count'] ?? 0 ),
			individualUse:      (bool) ( $row['individual_use'] ?? 0 ),
			dateStarts:         isset( $row['date_starts'] )  ? (int) $row['date_starts']  : null,
			dateExpires:        isset( $row['date_expires'] ) ? (int) $row['date_expires'] : null,
			status:             (string) ( $row['status'] ?? self::STATUS_ACTIVE ),
			description:        $row['description'] ?? null,
		);
	}
}
