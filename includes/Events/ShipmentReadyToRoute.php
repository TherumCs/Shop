<?php
/**
 * Fired (queued, one per shipment) after OrderPaid. Subscribers route
 * the shipment to the relevant vendor — Printful API, Printify API,
 * PodPartner, etc.
 *
 * Queued (not sync) so the webhook handler returns fast and vendor API
 * calls happen on a background worker. Action Scheduler picks it up if
 * available, else wp-cron.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

final readonly class ShipmentReadyToRoute implements Event {

	public function __construct(
		public int $orderId,
		public string $orderNumber,
		public int $shipmentId,
		public ?string $podProvider,
	) {}

	public static function name(): string { return 'shipment.ready_to_route'; }
}
