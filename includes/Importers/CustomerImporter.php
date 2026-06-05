<?php
/**
 * Shop by Therum — Customer importer.
 *
 * Reads a CSV (or any RFC-4180-ish delimited blob) and upserts every
 * row through `CustomerRepository::upsertByEmail()`. Field mapping is
 * auto-inferred from common header aliases (so a Shopify or codection
 * export drops in without manual mapping) but admins can override per
 * column via the `map` parameter.
 *
 * Conflict handling matches the repo's contract — 'skip' / 'update' /
 * 'replace'. Default 'update' (merge) is the safest: never clobbers
 * existing fields with blanks.
 *
 * Optionally creates a WP user account for each imported row when the
 * `create_wp_users` flag is on — useful for migrating a CRM where
 * everyone needs login access.
 */

namespace Shop\Importers;

use Shop\Repositories\CustomerRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CustomerImporter {

	/**
	 * Aliases → canonical field. Lowercased + stripped of spaces /
	 * underscores during match so 'Accepts Marketing', 'accepts-marketing',
	 * and 'AcceptsMarketing' all hit.
	 */
	private const ALIASES = [
		'email'             => [ 'email', 'emailaddress', 'mail' ],
		'first_name'        => [ 'firstname', 'fname', 'givenname' ],
		'last_name'         => [ 'lastname', 'lname', 'familyname', 'surname' ],
		'phone'             => [ 'phone', 'phonenumber', 'mobile', 'cell' ],
		'accepts_marketing' => [ 'acceptsmarketing', 'marketing', 'newsletter', 'optin' ],
		'address_line1'     => [ 'address', 'address1', 'addressline1', 'street', 'streetaddress', 'shipaddress' ],
		'address_line2'     => [ 'address2', 'addressline2', 'apt', 'suite', 'unit' ],
		'city'              => [ 'city', 'town' ],
		'state'             => [ 'state', 'province', 'region' ],
		'postal_code'       => [ 'postalcode', 'zip', 'zipcode', 'postcode' ],
		'country'           => [ 'country', 'countrycode' ],
		'tags'              => [ 'tags', 'labels' ],
	];

	public function __construct( private readonly CustomerRepository $customers ) {}

	/**
	 * @param array<int, array<string,string>>|null $rows  parsed rows; pass null and we'll parse CSV from $raw
	 * @param array<string,string>                  $map   override: header column → canonical field
	 * @return array{ created: int, updated: int, skipped: int, errors: array<int, string> }
	 */
	public function import( string $raw, ?array $rows = null, array $map = [], string $conflict = 'update', bool $createWpUsers = false ): array {
		if ( $rows === null ) $rows = $this->parseCsv( $raw );
		if ( ! $rows ) return $this->emptyResult();

		$headers = array_keys( $rows[0] );
		$mapping = $this->resolveMapping( $headers, $map );

		$out = $this->emptyResult();
		foreach ( $rows as $i => $row ) {
			$fields = $this->translateRow( $row, $mapping );
			$email  = (string) ( $fields['email'] ?? '' );
			if ( $email === '' ) {
				$out['errors'][] = "Row " . ( $i + 2 ) . ": missing email";
				continue;
			}
			try {
				if ( $createWpUsers && ! email_exists( $email ) ) {
					$user_id = wp_create_user( $email, wp_generate_password( 20, true, true ), $email );
					if ( ! is_wp_error( $user_id ) ) $fields['wp_user_id'] = (int) $user_id;
				} elseif ( $existing = get_user_by( 'email', $email ) ) {
					$fields['wp_user_id'] = (int) $existing->ID;
				}
				$res = $this->customers->upsertByEmail( $email, $fields, $conflict );
				$out[ $res['action'] ]++;
			} catch ( \Throwable $e ) {
				$out['errors'][] = "Row " . ( $i + 2 ) . ": " . $e->getMessage();
			}
		}
		return $out;
	}

	/**
	 * @return array{ created: int, updated: int, skipped: int, errors: array<int, string> }
	 */
	private function emptyResult(): array {
		return [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];
	}

	/**
	 * @param string[]             $headers
	 * @param array<string,string> $override
	 * @return array<string,string> CSV column → canonical field
	 */
	private function resolveMapping( array $headers, array $override ): array {
		$map = [];
		foreach ( $headers as $h ) {
			if ( isset( $override[ $h ] ) ) { $map[ $h ] = $override[ $h ]; continue; }
			$norm = strtolower( preg_replace( '/[\s_\-]/', '', $h ) );
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
			$field = $mapping[ $col ];
			$val   = trim( (string) $val );
			if ( $val === '' ) continue;
			if ( $field === 'accepts_marketing' ) {
				$out[ $field ] = in_array( strtolower( $val ), [ '1', 'true', 'yes', 'y' ], true );
			} elseif ( $field === 'tags' ) {
				$out[ $field ] = array_filter( array_map( 'trim', preg_split( '/[,;|]/', $val ) ?: [] ) );
			} else {
				$out[ $field ] = $val;
			}
		}
		return $out;
	}

	/**
	 * @return array<int, array<string,string>>
	 */
	private function parseCsv( string $raw ): array {
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw ); // strip BOM
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
