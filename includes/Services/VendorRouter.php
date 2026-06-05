<?php
/**
 * Shop by Therum — VendorRouter.
 *
 * On OrderPaid, queues one ShipmentReadyToRoute per shipment. Vendor
 * plugins / Nexus / WooOrderMirror subscribe to ShipmentReadyToRoute
 * to push the order to the correct vendor's fulfillment API.
 *
 * This is the connective tissue for multi-vendor orders. A cart with
 * lines from 3 vendors becomes 1 order → 3 shipments → 3 routing
 * events → 3 vendor pipelines.
 *
 * Local-stock shipments (pod_provider = null) still fire an event so
 * subscribers that want to hear about every shipment can. Plugins
 * filter by provider in their handler.
 */

namespace Shop\Services;

use Shop\Events\EventBus;
use Shop\Events\OrderPaid;
use Shop\Events\ShipmentReadyToRoute;
use Shop\Repositories\OrderShipmentRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class VendorRouter {

	public function __construct(
		private readonly OrderShipmentRepository $shipments,
		private readonly EventBus $events,
	) {}

	public function onOrderPaid( OrderPaid $event ): void {
		$shipments = $this->shipments->forOrder( $event->orderId, $event->currency );
		foreach ( $shipments as $s ) {
			$this->events->queue( new ShipmentReadyToRoute(
				orderId:     $event->orderId,
				orderNumber: $event->orderNumber,
				shipmentId:  $s->id,
				podProvider: $s->podProvider,
			) );
		}
	}
}
