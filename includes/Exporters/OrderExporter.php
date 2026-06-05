<?php
/**
 * Shop by Therum — Order exporter.
 *
 * Two formats:
 *
 *   - flat CSV (default) — one row per line item, joined by order_number.
 *     A 3-item order produces 3 rows; the order-level columns (totals,
 *     addresses, payment) repeat on each row. This is what WebToffee /
 *     Shopify export, and what most spreadsheet-driven workflows expect.
 *
 *   - JSON — nested objects, one entry per order with its items[] array.
 *     Use for backups + cross-store migration.
 *
 * Both formats roundtrip cleanly through `OrderImporter`. The flat CSV
 * gets a UTF-8 BOM so Excel autodetects encoding.
 *
 * Streamed in batches (200 orders / page) so a 100k-order export
 * doesn't blow memory.
 */

namespace Shop\Exporters;

use Shop\Models\Order;
use Shop\Models\OrderItem;
use Shop\Repositories\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderExporter {

	public function __construct( private readonly OrderRepository $orders ) {}

	public function id(): string          { return 'orders'; }
	public function displayName(): string { return 'Orders'; }

	public const COLUMNS = [
		// Order-level (repeats per line item)
		'order_number', 'order_status', 'order_email', 'order_currency',
		'order_subtotal', 'order_shipping', 'order_tax', 'order_discount',
		'order_total', 'order_refunded',
		'payment_provider', 'payment_method', 'payment_intent_id',
		'created_at', 'paid_at',
		// Ship address
		'ship_name', 'ship_line1', 'ship_line2', 'ship_city', 'ship_state',
		'ship_postal_code', 'ship_country', 'ship_phone',
		// Bill address
		'bill_name', 'bill_line1', 'bill_line2', 'bill_city', 'bill_state',
		'bill_postal_code', 'bill_country', 'bill_phone',
		// Line-item
		'item_product_id', 'item_variant_id', 'item_sku', 'item_title',
		'item_quantity', 'item_unit_price', 'item_line_total', 'item_discount',
		'item_vendor_unit_cost',
		'item_fulfillment_status', 'item_fulfillment_provider',
		'item_fulfillment_id', 'item_tracking_number', 'item_tracking_carrier',
	];

	public function exportCsv( ?array $filters = null ): string {
		$out = fopen( 'php://temp', 'r+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, self::COLUMNS );
		$this->streamOrders( $filters, function ( Order $order ) use ( $out ) {
			foreach ( $order->items as $item ) {
				fputcsv( $out, $this->flatRow( $order, $item ) );
			}
			// Edge case — order has no items (rare but possible mid-build).
			if ( ! $order->items ) fputcsv( $out, $this->flatRow( $order, null ) );
		} );
		rewind( $out );
		return (string) stream_get_contents( $out );
	}

	public function exportJson( ?array $filters = null ): string {
		$bag = [];
		$this->streamOrders( $filters, function ( Order $order ) use ( &$bag ) {
			$bag[] = $this->nested( $order );
		} );
		return (string) wp_json_encode( $bag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param array<string,mixed>|null $filters  status, date_from, date_to
	 */
	private function streamOrders( ?array $filters, callable $emit ): void {
		$offset = 0;
		$page   = 200;
		while ( true ) {
			$batch = $this->orders->list( $filters ?? [], $page, $offset );
			if ( ! $batch ) break;
			foreach ( $batch as $o ) $emit( $o );
			if ( count( $batch ) < $page ) break;
			$offset += $page;
		}
	}

	/** @return string[] */
	private function flatRow( Order $o, ?OrderItem $i ): array {
		$ship = (array) ( $o->shipAddress ?? [] );
		$bill = (array) ( $o->billAddress ?? [] );
		return [
			$o->number, $o->status, $o->email, $o->currency,
			$this->money( $o->subtotal->amount ),
			$this->money( $o->shippingTotal->amount ),
			$this->money( $o->taxTotal->amount ),
			$this->money( $o->discountTotal->amount ),
			$this->money( $o->grandTotal->amount ),
			$this->money( $o->refundedTotal->amount ),
			(string) $o->paymentProvider,
			(string) $o->paymentMethod,
			(string) $o->paymentIntentId,
			gmdate( 'c', $o->createdAt ),
			$o->paidAt ? gmdate( 'c', $o->paidAt ) : '',
			(string) ( $ship['name']        ?? '' ),
			(string) ( $ship['line1']       ?? '' ),
			(string) ( $ship['line2']       ?? '' ),
			(string) ( $ship['city']        ?? '' ),
			(string) ( $ship['state']       ?? '' ),
			(string) ( $ship['postal_code'] ?? '' ),
			(string) ( $ship['country']     ?? '' ),
			(string) ( $ship['phone']       ?? '' ),
			(string) ( $bill['name']        ?? '' ),
			(string) ( $bill['line1']       ?? '' ),
			(string) ( $bill['line2']       ?? '' ),
			(string) ( $bill['city']        ?? '' ),
			(string) ( $bill['state']       ?? '' ),
			(string) ( $bill['postal_code'] ?? '' ),
			(string) ( $bill['country']     ?? '' ),
			(string) ( $bill['phone']       ?? '' ),
			(string) ( $i?->productId ?? '' ),
			(string) ( $i?->variantId ?? '' ),
			(string) ( $i?->sku       ?? '' ),
			(string) ( $i?->title     ?? '' ),
			(string) ( $i?->quantity  ?? '' ),
			$i ? $this->money( $i->unitPrice->amount )     : '',
			$i ? $this->money( $i->lineTotal->amount )     : '',
			$i ? $this->money( $i->discountTotal->amount ) : '',
			$i && $i->vendorUnitCost ? $this->money( $i->vendorUnitCost->amount ) : '',
			(string) ( $i?->fulfillmentStatus   ?? '' ),
			(string) ( $i?->fulfillmentProvider ?? '' ),
			(string) ( $i?->fulfillmentId       ?? '' ),
			(string) ( $i?->trackingNumber      ?? '' ),
			(string) ( $i?->trackingCarrier     ?? '' ),
		];
	}

	/** @return array<string,mixed> */
	private function nested( Order $o ): array {
		return [
			'number'   => $o->number,
			'status'   => $o->status,
			'email'    => $o->email,
			'currency' => $o->currency,
			'totals'   => [
				'subtotal' => $o->subtotal->amount,
				'shipping' => $o->shippingTotal->amount,
				'tax'      => $o->taxTotal->amount,
				'discount' => $o->discountTotal->amount,
				'grand'    => $o->grandTotal->amount,
				'refunded' => $o->refundedTotal->amount,
			],
			'payment'  => [
				'provider'  => $o->paymentProvider,
				'method'    => $o->paymentMethod,
				'intent_id' => $o->paymentIntentId,
				'paid_at'   => $o->paidAt,
			],
			'ship_address' => $o->shipAddress,
			'bill_address' => $o->billAddress,
			'items' => array_map( fn( OrderItem $i ) => [
				'product_id'     => $i->productId,
				'variant_id'     => $i->variantId,
				'sku'            => $i->sku,
				'title'          => $i->title,
				'quantity'       => $i->quantity,
				'unit_price'     => $i->unitPrice->amount,
				'line_total'     => $i->lineTotal->amount,
				'discount_total' => $i->discountTotal->amount,
				'vendor_unit_cost' => $i->vendorUnitCost?->amount,
				'fulfillment'    => [
					'status'   => $i->fulfillmentStatus,
					'provider' => $i->fulfillmentProvider,
					'id'       => $i->fulfillmentId,
					'tracking' => [
						'number'  => $i->trackingNumber,
						'carrier' => $i->trackingCarrier,
					],
				],
			], $o->items ),
			'created_at' => $o->createdAt,
			'updated_at' => $o->updatedAt,
		];
	}

	private function money( int $cents ): string {
		return number_format( $cents / 100, 2, '.', '' );
	}
}
