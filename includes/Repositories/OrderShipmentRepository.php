<?php
/**
 * Shop by Therum — OrderShipmentRepository.
 *
 * Read + mutate order_shipments. Created by OrderRepository::createFromCart;
 * later mutated by ShippingStep (when real per-vendor quotes wire up) and
 * by fulfillment webhooks (vendor shipped → tracking + status change).
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\OrderShipment;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderShipmentRepository {

	/** @return OrderShipment[] */
	public function forOrder( int $orderId, string $currency = 'USD' ): array {
		$stmt = DB::pdo()->prepare(
			"SELECT * FROM order_shipments WHERE order_id = :o ORDER BY id ASC"
		);
		$stmt->execute( [ ':o' => $orderId ] );
		return array_map(
			fn( array $r ): OrderShipment => OrderShipment::fromRow( $r, $currency ),
			$stmt->fetchAll()
		);
	}

	public function findById( int $id, string $currency = 'USD' ): ?OrderShipment {
		$stmt = DB::pdo()->prepare( "SELECT * FROM order_shipments WHERE id = :i" );
		$stmt->execute( [ ':i' => $id ] );
		$row = $stmt->fetch();
		return $row ? OrderShipment::fromRow( $row, $currency ) : null;
	}

	public function setQuote(
		int $shipmentId,
		int $shippingMinor,
		int $taxMinor,
		string $taxSource = 'vendor_quote',
		?string $quoteRef = null,
	): void {
		DB::pdo()->prepare(
			"UPDATE order_shipments
			    SET shipping_total = :s,
			        tax_total = :t,
			        tax_source = :ts,
			        quote_ref = :qr,
			        quoted_at = unixepoch(),
			        updated_at = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':s'  => $shippingMinor,
			':t'  => $taxMinor,
			':ts' => $taxSource,
			':qr' => $quoteRef,
			':i'  => $shipmentId,
		] );
	}

	public function markShipped( int $shipmentId, string $trackingNumber, ?string $carrier ): void {
		DB::pdo()->prepare(
			"UPDATE order_shipments
			    SET status = 'shipped',
			        tracking_number = :tn,
			        tracking_carrier = :tc,
			        shipped_at = unixepoch(),
			        updated_at = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':tn' => $trackingNumber,
			':tc' => $carrier,
			':i'  => $shipmentId,
		] );
	}

	public function markDelivered( int $shipmentId ): void {
		DB::pdo()->prepare(
			"UPDATE order_shipments
			    SET status = 'delivered',
			        delivered_at = unixepoch(),
			        updated_at = unixepoch()
			  WHERE id = :i"
		)->execute( [ ':i' => $shipmentId ] );
	}
}
