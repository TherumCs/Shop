<?php
/**
 * Shop by Therum — PaymentGatewayRegistry.
 *
 * Keeps the set of available PSP gateways. Bootstrap registers each one
 * (Mock in v1; Square in #8). CheckoutService and WebhookReceiver resolve
 * by id() — the same string stored in orders.payment_provider and
 * payment_events.payment_provider.
 *
 * Not in /Repositories/ in the DB sense — this is an in-memory registry.
 * Lives here for ergonomic grouping with the other "find a thing by id"
 * classes.
 */

namespace Shop\Repositories;

use Shop\Payments\PSPGateway;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PaymentGatewayRegistry {

	/** @var array<string, PSPGateway> */
	private array $gateways = [];

	public function register( PSPGateway $gateway ): void {
		$this->gateways[ $gateway->id() ] = $gateway;
	}

	public function get( string $id ): PSPGateway {
		if ( ! isset( $this->gateways[ $id ] ) ) {
			throw new \DomainException( "Unknown payment gateway: {$id}" );
		}
		return $this->gateways[ $id ];
	}

	public function has( string $id ): bool {
		return isset( $this->gateways[ $id ] );
	}

	/** @return PSPGateway[] */
	public function all(): array {
		return array_values( $this->gateways );
	}
}
