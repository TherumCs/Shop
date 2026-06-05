<?php
/**
 * Shop by Therum — Customer exporter.
 *
 * Streams every customer to CSV (default) or JSON, matching the column
 * order most CRMs expect. The CSV is plain RFC-4180 with a UTF-8 BOM
 * (so Excel autodetects encoding correctly) and lifetime stats are
 * formatted as numbers, not money strings — easier downstream parsing.
 *
 * Designed to be wired into the existing `ExporterRegistry` so it
 * surfaces in the spreadsheet admin's Export menu alongside the
 * product / order exporters.
 */

namespace Shop\Exporters;

use Shop\Models\Customer;
use Shop\Repositories\CustomerRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CustomerExporter {

	public function __construct( private readonly CustomerRepository $customers ) {}

	public function id(): string          { return 'customers'; }
	public function displayName(): string { return 'Customers'; }

	/** Exposed for tests + REST so the column header order is documented in one place. */
	public const COLUMNS = [
		'email', 'first_name', 'last_name', 'phone',
		'accepts_marketing',
		'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
		'tags',
		'orders_count', 'total_spent', 'last_order_at',
		'created_at', 'updated_at',
	];

	public function exportCsv(): string {
		$out = fopen( 'php://temp', 'r+' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM
		fputcsv( $out, self::COLUMNS );
		$offset = 0;
		while ( true ) {
			$batch = $this->customers->list( null, 500, $offset );
			if ( ! $batch ) break;
			foreach ( $batch as $c ) fputcsv( $out, $this->toRow( $c ) );
			if ( count( $batch ) < 500 ) break;
			$offset += 500;
		}
		rewind( $out );
		return (string) stream_get_contents( $out );
	}

	public function exportJson(): string {
		$rows = [];
		$offset = 0;
		while ( true ) {
			$batch = $this->customers->list( null, 500, $offset );
			if ( ! $batch ) break;
			foreach ( $batch as $c ) $rows[] = array_combine( self::COLUMNS, $this->toRow( $c ) );
			if ( count( $batch ) < 500 ) break;
			$offset += 500;
		}
		return (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @return array<int, string>
	 */
	private function toRow( Customer $c ): array {
		return [
			$c->email,
			(string) $c->first_name,
			(string) $c->last_name,
			(string) $c->phone,
			$c->accepts_marketing ? '1' : '0',
			(string) $c->address_line1,
			(string) $c->address_line2,
			(string) $c->city,
			(string) $c->state,
			(string) $c->postal_code,
			(string) $c->country,
			implode( ',', $c->tags ),
			(string) $c->orders_count,
			number_format( $c->total_spent_cents / 100, 2, '.', '' ),
			$c->last_order_at ? gmdate( 'c', $c->last_order_at ) : '',
			gmdate( 'c', $c->created_at ),
			gmdate( 'c', $c->updated_at ),
		];
	}
}
