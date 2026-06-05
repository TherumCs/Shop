<?php
/**
 * Shop by Therum — canonical webhook event.
 *
 * Provider-neutral shape after gateway-specific parsing. The webhook
 * receiver routes off `kind` to internal handlers.
 *
 * Canonical kinds (extend as needed; receivers ignore unknown):
 *   payment.succeeded   payment.failed   payment.refunded
 *   refund.succeeded    refund.failed
 *   dispute.opened      dispute.lost     dispute.won
 */

namespace Shop\Payments;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class WebhookEvent {

	public function __construct(
		public string $providerId,
		public string $providerEventId,
		public string $kind,
		public ?string $paymentIntentId = null,
		public ?string $refundId        = null,
		/** @var array<string,mixed> */
		public array $payload           = [],
	) {}
}
