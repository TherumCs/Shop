<?php
/**
 * Shop by Therum — Studio Pay payouts.
 *
 * Three cadences:
 *   - daily    — provider auto-handles; we do nothing
 *   - instant  — after every successful capture, request an instant
 *                payout for that amount (provider fee 1.5–1.75%)
 *   - manual   — admin clicks "Pay out now" in the dashboard
 *
 * Cadence is stored in `shop_studio_pay_payout_cadence` (default 'daily').
 * Provider routing — we payout via the same provider that captured the
 * payment, so an order paid by Stripe pays out via Stripe.
 *
 * Idempotency — payout keys are `payout-{order_id}-{ts_minute}` so a
 * retry inside the same minute can't double-pay.
 */

namespace Shop\Payments\Studio;

use Shop\Models\Order;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Payouts {

	public function __construct( private readonly StudioPay $studio ) {}

	public function cadence(): string {
		return (string) get_option( 'shop_studio_pay_payout_cadence', 'daily' );
	}

	public function setCadence( string $value ): void {
		if ( ! in_array( $value, [ 'daily', 'instant', 'manual' ], true ) ) {
			throw new \InvalidArgumentException( "Unknown cadence '$value'." );
		}
		update_option( 'shop_studio_pay_payout_cadence', $value );
	}

	/**
	 * Triggered after a payment succeeds — automatic instant payout if
	 * the merchant opted into per-order instant.
	 */
	public function onPaymentSucceeded( Order $order ): ?string {
		if ( $this->cadence() !== 'instant' ) return null;
		$providerId = (string) ( $order->payment_provider ?? '' );
		$provider   = $this->studio->providers()[ $providerId ] ?? null;
		if ( $provider === null ) return null;
		try {
			return $provider->payout(
				$order->grandTotal,
				instant: true,
				idempotencyKey: 'payout-' . $order->id . '-' . gmdate( 'YmdHi' ),
			);
		} catch ( \Throwable $e ) {
			// Don't blow up the capture flow if instant payout fails —
			// the funds are still there, just on the standard cycle.
			error_log( '[ShopByTherum] Instant payout failed: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Manual payout — admin requested. Pulls the largest available
	 * balance across connected providers; multi-provider merchants get
	 * multiple payout ids back.
	 *
	 * @return array<int, array{ provider: string, id: string }>
	 */
	public function payoutAll( bool $instant ): array {
		$out = [];
		foreach ( $this->studio->providers() as $id => $p ) {
			if ( ! $p->isConnected() ) continue;
			$bal = $p->availableBalance();
			if ( $bal === null || $bal->amount <= 0 ) continue;
			try {
				$payoutId = $p->payout( $bal, $instant, 'manual-' . $id . '-' . gmdate( 'YmdHi' ) );
				$out[] = [ 'provider' => $id, 'id' => $payoutId ];
			} catch ( \Throwable $e ) {
				$out[] = [ 'provider' => $id, 'id' => '', 'error' => $e->getMessage() ];
			}
		}
		return $out;
	}

	/**
	 * Aggregate balance across every connected provider. Multi-provider
	 * merchants see one number in the admin dashboard, with a breakdown
	 * on hover.
	 *
	 * @return array{ total: Money, by_provider: array<string, Money> }
	 */
	public function aggregateBalance(): array {
		$totalCents = 0;
		$currency   = 'USD';
		$byProvider = [];
		foreach ( $this->studio->providers() as $id => $p ) {
			if ( ! $p->isConnected() ) continue;
			$bal = $p->availableBalance();
			if ( $bal === null ) continue;
			$byProvider[ $id ] = $bal;
			$totalCents += $bal->amount;
			$currency    = $bal->currency;
		}
		return [
			'total'       => new Money( $totalCents, $currency ),
			'by_provider' => $byProvider,
		];
	}
}
