<?php
/**
 * Shop by Therum — Refund DTO.
 */

namespace Shop\Models;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Refund {

	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly int $orderId,
		public readonly Money $amount,
		public readonly ?string $reason,
		public readonly string $status,
		public readonly string $initiatedBy,
		public readonly ?int $refundedByUserId,
		public readonly ?string $paymentProvider,
		public readonly ?string $gatewayRefundId,
		public readonly ?string $notes,
		public readonly ?string $failureReason,
		public readonly int $createdAt,
		public readonly int $updatedAt,
		public readonly ?int $completedAt,
	) {}

	/** @param array<string,mixed> $row */
	public static function fromRow( array $row, string $currency = 'USD' ): self {
		return new self(
			id:                 (int) $row['id'],
			uuid:               (string) $row['uuid'],
			orderId:            (int) $row['order_id'],
			amount:             Money::cents( (int) $row['amount'], $currency ),
			reason:             $row['reason'] ?? null,
			status:             (string) $row['status'],
			initiatedBy:        (string) ( $row['initiated_by'] ?? 'admin' ),
			refundedByUserId:   isset( $row['refunded_by_user_id'] ) ? (int) $row['refunded_by_user_id'] : null,
			paymentProvider:    $row['payment_provider']  ?? null,
			gatewayRefundId:    $row['gateway_refund_id'] ?? null,
			notes:              $row['notes']             ?? null,
			failureReason:      $row['failure_reason']    ?? null,
			createdAt:          (int) $row['created_at'],
			updatedAt:          (int) $row['updated_at'],
			completedAt:        isset( $row['completed_at'] ) ? (int) $row['completed_at'] : null,
		);
	}
}
