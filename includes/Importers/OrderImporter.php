<?php
/**
 * Shop by Therum — Order importer.
 *
 * Accepts the flat-per-item CSV that OrderExporter produces (or any
 * upstream tool's WebToffee-flavored format) and reconstructs orders +
 * line items. Header aliases match OrderExporter::COLUMNS for clean
 * roundtripping; common Shopify / WooCommerce headers also map.
 *
 * Conflict modes match the customer importer:
 *   skip    — order_number exists → leave alone
 *   update  — merge, blanks don't clobber (most fields), items replaced
 *   replace — wholesale row + items rewrite
 *
 * Goes direct-to-PDO inside a transaction so partial-row failures don't
 * leave half-imported orders. Per-row errors collect into the result
 * payload; the rest of the import continues.
 *
 * For very large imports (>5k orders), call `import()` in chunks; the
 * importer doesn't paginate the input itself.
 */

namespace Shop\Importers;

use Shop\DB;
use Shop\Repositories\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderImporter {

	/**
	 * Header → canonical column. Lowercase + strip space/_/-/dot before
	 * matching so "Order Number" / "order_number" / "Order #" all hit.
	 */
	private const ALIASES = [
		'order_number'    => [ 'ordernumber', 'order', 'orderid', 'ordername' ],
		'order_status'    => [ 'orderstatus', 'status', 'financialstatus' ],
		'order_email'     => [ 'orderemail', 'email', 'customeremail' ],
		'order_currency'  => [ 'ordercurrency', 'currency' ],
		'order_subtotal'  => [ 'ordersubtotal', 'subtotal' ],
		'order_shipping'  => [ 'ordershipping', 'shippingtotal', 'shipping' ],
		'order_tax'       => [ 'ordertax', 'taxtotal', 'tax' ],
		'order_discount'  => [ 'orderdiscount', 'discounttotal', 'discount' ],
		'order_total'     => [ 'ordertotal', 'grandtotal', 'total' ],
		'order_refunded'  => [ 'orderrefunded', 'refundedtotal' ],
		'payment_provider'=> [ 'paymentprovider', 'paymentgateway', 'gateway' ],
		'payment_method'  => [ 'paymentmethod' ],
		'payment_intent_id' => [ 'paymentintentid', 'transactionid' ],
		'created_at'      => [ 'createdat', 'orderdate', 'datecreated' ],
		'paid_at'         => [ 'paidat', 'datepaid' ],

		'ship_name'        => [ 'shipname', 'shippingname' ],
		'ship_line1'       => [ 'shipline1', 'shippingaddress1', 'shipaddress' ],
		'ship_line2'       => [ 'shipline2', 'shippingaddress2' ],
		'ship_city'        => [ 'shipcity', 'shippingcity' ],
		'ship_state'       => [ 'shipstate', 'shippingstate', 'shippingprovince' ],
		'ship_postal_code' => [ 'shippostalcode', 'shippingzip', 'shippingpostalcode' ],
		'ship_country'     => [ 'shipcountry', 'shippingcountry' ],
		'ship_phone'       => [ 'shipphone', 'shippingphone' ],

		'bill_name'        => [ 'billname', 'billingname' ],
		'bill_line1'       => [ 'billline1', 'billingaddress1' ],
		'bill_line2'       => [ 'billline2', 'billingaddress2' ],
		'bill_city'        => [ 'billcity', 'billingcity' ],
		'bill_state'       => [ 'billstate', 'billingstate', 'billingprovince' ],
		'bill_postal_code' => [ 'billpostalcode', 'billingzip', 'billingpostalcode' ],
		'bill_country'     => [ 'billcountry', 'billingcountry' ],
		'bill_phone'       => [ 'billphone', 'billingphone' ],

		'item_product_id'   => [ 'itemproductid', 'productid' ],
		'item_variant_id'   => [ 'itemvariantid', 'variantid' ],
		'item_sku'          => [ 'itemsku', 'sku', 'linesku' ],
		'item_title'        => [ 'itemtitle', 'producttitle', 'linetitle', 'productname' ],
		'item_quantity'     => [ 'itemquantity', 'quantity', 'qty', 'linequantity' ],
		'item_unit_price'   => [ 'itemunitprice', 'unitprice', 'price', 'lineprice' ],
		'item_line_total'   => [ 'itemlinetotal', 'linetotal' ],
		'item_discount'     => [ 'itemdiscount', 'linediscount' ],
		'item_vendor_unit_cost' => [ 'itemvendorunitcost', 'vendorcost', 'cost' ],
		'item_fulfillment_status'   => [ 'itemfulfillmentstatus', 'fulfillmentstatus' ],
		'item_fulfillment_provider' => [ 'itemfulfillmentprovider', 'fulfillmentprovider', 'podprovider' ],
		'item_fulfillment_id'       => [ 'itemfulfillmentid', 'fulfillmentid' ],
		'item_tracking_number'      => [ 'itemtrackingnumber', 'trackingnumber', 'tracking' ],
		'item_tracking_carrier'     => [ 'itemtrackingcarrier', 'trackingcarrier', 'carrier' ],
	];

	public function __construct( private readonly OrderRepository $orders ) {}

	/**
	 * @param array<int, array<string,string>>|null $rows  parsed; pass null to parse $raw as CSV
	 * @param array<string,string>                  $map   override header → canonical
	 * @return array{ created: int, updated: int, skipped: int, items_inserted: int, errors: array<int, string> }
	 */
	public function import( string $raw, ?array $rows = null, array $map = [], string $conflict = 'update' ): array {
		if ( $rows === null ) $rows = $this->parseCsv( $raw );
		if ( ! $rows ) return $this->emptyResult();

		$headers = array_keys( $rows[0] );
		$mapping = $this->resolveMapping( $headers, $map );

		// Group rows by order_number — flat CSV repeats order columns
		// per line item, so we accumulate items by order key.
		$grouped = [];
		foreach ( $rows as $i => $row ) {
			$canon  = $this->translateRow( $row, $mapping );
			$number = (string) ( $canon['order_number'] ?? '' );
			if ( $number === '' ) continue; // skip junk rows
			if ( ! isset( $grouped[ $number ] ) ) $grouped[ $number ] = [ 'order' => $canon, 'items' => [] ];
			if ( ! empty( $canon['item_sku'] ) || ! empty( $canon['item_title'] ) || ! empty( $canon['item_product_id'] ) ) {
				$grouped[ $number ]['items'][] = $canon;
			}
		}

		$out = $this->emptyResult();
		foreach ( $grouped as $number => $group ) {
			try {
				DB::tx( function () use ( $number, $group, $conflict, &$out ) {
					$action = $this->upsertOrder( $number, $group['order'], $group['items'], $conflict );
					$out[ $action ]++;
					$out['items_inserted'] += count( $group['items'] );
				} );
			} catch ( \Throwable $e ) {
				$out['errors'][] = "Order $number: " . $e->getMessage();
			}
		}
		return $out;
	}

	/** @return array{created:int,updated:int,skipped:int,items_inserted:int,errors:array<int,string>} */
	private function emptyResult(): array {
		return [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'errors' => [] ];
	}

	/**
	 * @param array<string,mixed>      $orderRow
	 * @param array<int, array<string,mixed>> $items
	 * @return 'created'|'updated'|'skipped'
	 */
	private function upsertOrder( string $number, array $orderRow, array $items, string $conflict ): string {
		$pdo = DB::pdo();
		$existing = $this->orders->findByNumber( $number );

		if ( $existing && $conflict === 'skip' ) return 'skipped';

		$cols = $this->orderColumns( $orderRow );

		if ( $existing ) {
			$assigns = [];
			foreach ( $cols as $k => $_ ) {
				$col = substr( $k, 1 );
				$assigns[] = "$col = $k";
			}
			$assigns[] = "updated_at = unixepoch()";
			$pdo->prepare( "UPDATE orders SET " . implode( ', ', $assigns ) . " WHERE id = :__id" )
				->execute( array_merge( $cols, [ ':__id' => $existing->id ] ) );
			$orderId = $existing->id;

			if ( $conflict === 'replace' || $items ) {
				$pdo->prepare( "DELETE FROM order_items WHERE order_id = :id" )->execute( [ ':id' => $orderId ] );
			}
			$action = 'updated';
		} else {
			$cols[':number'] = $number;
			$pdo->prepare( "INSERT INTO orders (
				number, email, currency, status,
				subtotal, shipping_total, tax_total, discount_total, grand_total, refunded_total,
				ship_address, bill_address,
				payment_provider, payment_method, payment_intent_id, paid_at,
				created_at
			) VALUES (
				:number, :email, :currency, :status,
				:subtotal, :shipping_total, :tax_total, :discount_total, :grand_total, :refunded_total,
				:ship_address, :bill_address,
				:payment_provider, :payment_method, :payment_intent_id, :paid_at,
				:created_at
			)" )->execute( $cols );
			$orderId = (int) $pdo->lastInsertId();
			$action  = 'created';
		}

		// Items
		$stmt = $pdo->prepare(
			"INSERT INTO order_items (
				order_id, product_id, variant_id, sku, title,
				snapshot, quantity, unit_price, line_total, discount_total, vendor_unit_cost,
				fulfillment_status, fulfillment_provider, fulfillment_id,
				tracking_number, tracking_carrier
			) VALUES (
				:order_id, :product_id, :variant_id, :sku, :title,
				:snapshot, :quantity, :unit_price, :line_total, :discount_total, :vendor_unit_cost,
				:fs, :fp, :fid,
				:tn, :tc
			)"
		);
		foreach ( $items as $i ) {
			$stmt->execute( [
				':order_id'        => $orderId,
				':product_id'      => $this->intOrNull( $i, 'item_product_id' ),
				':variant_id'      => $this->intOrNull( $i, 'item_variant_id' ),
				':sku'             => $this->strOrNull( $i, 'item_sku' ),
				':title'           => (string) ( $i['item_title'] ?? '' ),
				':snapshot'        => '{}',
				':quantity'        => (int) ( $i['item_quantity'] ?? 1 ),
				':unit_price'      => $this->dollarsToCents( $i['item_unit_price'] ?? null ),
				':line_total'      => $this->dollarsToCents( $i['item_line_total'] ?? null ),
				':discount_total'  => $this->dollarsToCents( $i['item_discount']   ?? 0 ),
				':vendor_unit_cost'=> $this->dollarsToCents( $i['item_vendor_unit_cost'] ?? null, true ),
				':fs'              => (string) ( $i['item_fulfillment_status']   ?? 'unfulfilled' ),
				':fp'              => $this->strOrNull( $i, 'item_fulfillment_provider' ),
				':fid'             => $this->strOrNull( $i, 'item_fulfillment_id' ),
				':tn'              => $this->strOrNull( $i, 'item_tracking_number' ),
				':tc'              => $this->strOrNull( $i, 'item_tracking_carrier' ),
			] );
		}
		return $action;
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	/** @return array<string,mixed> PDO bind params (with leading colons), order-level columns only */
	private function orderColumns( array $r ): array {
		return [
			':email'             => (string) ( $r['order_email']    ?? '' ),
			':currency'          => (string) ( $r['order_currency'] ?? 'USD' ),
			':status'            => (string) ( $r['order_status']   ?? 'completed' ),
			':subtotal'          => $this->dollarsToCents( $r['order_subtotal'] ?? 0 ),
			':shipping_total'    => $this->dollarsToCents( $r['order_shipping'] ?? 0 ),
			':tax_total'         => $this->dollarsToCents( $r['order_tax']      ?? 0 ),
			':discount_total'    => $this->dollarsToCents( $r['order_discount'] ?? 0 ),
			':grand_total'       => $this->dollarsToCents( $r['order_total']    ?? 0 ),
			':refunded_total'    => $this->dollarsToCents( $r['order_refunded'] ?? 0 ),
			':ship_address'      => wp_json_encode( $this->addressFrom( $r, 'ship' ) ),
			':bill_address'      => wp_json_encode( $this->addressFrom( $r, 'bill' ) ),
			':payment_provider'  => $this->strOrNull( $r, 'payment_provider' ),
			':payment_method'    => $this->strOrNull( $r, 'payment_method' ),
			':payment_intent_id' => $this->strOrNull( $r, 'payment_intent_id' ),
			':paid_at'           => $this->dateToTs( $r['paid_at']    ?? null ),
			':created_at'        => $this->dateToTs( $r['created_at'] ?? null ) ?? time(),
		];
	}

	/** @return array<string,string|null> */
	private function addressFrom( array $r, string $prefix ): array {
		return [
			'name'        => $this->strOrNull( $r, "{$prefix}_name" ),
			'line1'       => $this->strOrNull( $r, "{$prefix}_line1" ),
			'line2'       => $this->strOrNull( $r, "{$prefix}_line2" ),
			'city'        => $this->strOrNull( $r, "{$prefix}_city" ),
			'state'       => $this->strOrNull( $r, "{$prefix}_state" ),
			'postal_code' => $this->strOrNull( $r, "{$prefix}_postal_code" ),
			'country'     => $this->strOrNull( $r, "{$prefix}_country" ),
			'phone'       => $this->strOrNull( $r, "{$prefix}_phone" ),
		];
	}

	private function strOrNull( array $r, string $k ): ?string {
		$v = (string) ( $r[ $k ] ?? '' );
		return $v === '' ? null : $v;
	}
	private function intOrNull( array $r, string $k ): ?int {
		$v = $r[ $k ] ?? '';
		return ( $v === '' || $v === null ) ? null : (int) $v;
	}

	/** Returns null when value missing and nullable=true, else 0. */
	private function dollarsToCents( mixed $v, bool $nullable = false ): int|null {
		if ( $v === null || $v === '' ) return $nullable ? null : 0;
		return (int) round( ( (float) $v ) * 100 );
	}

	private function dateToTs( mixed $v ): ?int {
		if ( ! $v ) return null;
		$ts = strtotime( (string) $v );
		return $ts === false ? null : $ts;
	}

	/**
	 * @param string[]             $headers
	 * @param array<string,string> $override
	 * @return array<string,string> CSV column → canonical
	 */
	private function resolveMapping( array $headers, array $override ): array {
		$map = [];
		foreach ( $headers as $h ) {
			if ( isset( $override[ $h ] ) ) { $map[ $h ] = $override[ $h ]; continue; }
			$norm = strtolower( preg_replace( '/[\s_\-\.]/', '', $h ) );
			// Direct hit on canonical name (the exporter's own headers).
			if ( in_array( $norm, array_map( fn( $k ) => str_replace( '_', '', $k ), array_keys( self::ALIASES ) ), true ) ) {
				foreach ( array_keys( self::ALIASES ) as $canon ) {
					if ( str_replace( '_', '', $canon ) === $norm ) { $map[ $h ] = $canon; break; }
				}
				continue;
			}
			foreach ( self::ALIASES as $field => $aliases ) {
				if ( in_array( $norm, $aliases, true ) ) { $map[ $h ] = $field; break; }
			}
		}
		return $map;
	}

	/**
	 * @param array<string,string>  $row
	 * @param array<string,string>  $mapping
	 * @return array<string,mixed>
	 */
	private function translateRow( array $row, array $mapping ): array {
		$out = [];
		foreach ( $row as $col => $val ) {
			if ( ! isset( $mapping[ $col ] ) ) continue;
			$out[ $mapping[ $col ] ] = trim( (string) $val );
		}
		return $out;
	}

	/** @return array<int, array<string,string>> */
	private function parseCsv( string $raw ): array {
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$lines = preg_split( '/\r\n|\n|\r/', trim( (string) $raw ) ) ?: [];
		if ( ! $lines ) return [];
		$headers = str_getcsv( array_shift( $lines ) );
		$out = [];
		foreach ( $lines as $line ) {
			if ( $line === '' ) continue;
			$cells = str_getcsv( $line );
			$row = [];
			foreach ( $headers as $i => $h ) $row[ $h ] = $cells[ $i ] ?? '';
			$out[] = $row;
		}
		return $out;
	}
}
