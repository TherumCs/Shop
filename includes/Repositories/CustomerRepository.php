<?php
/**
 * Shop by Therum — CustomerRepository.
 *
 * The CRUD surface for the customers table. Designed for two consumers:
 *
 *   1. CheckoutService — upsert by email at the end of every order so
 *      a guest checkout becomes a tracked customer automatically.
 *
 *   2. The CustomerImporter / spreadsheet admin — bulk operations with
 *      configurable conflict handling.
 *
 * The schema's UNIQUE on email is the conflict key — duplicate emails
 * are impossible at the DB level; the upsert helpers below handle the
 * "already exists" case explicitly so callers don't have to catch
 * UNIQUE-constraint exceptions.
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\Customer;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CustomerRepository {

	public function findById( int $id ): ?Customer {
		$stmt = DB::pdo()->prepare( "SELECT * FROM customers WHERE id = :id" );
		$stmt->execute( [ ':id' => $id ] );
		$row = $stmt->fetch();
		return $row ? Customer::fromRow( $row ) : null;
	}

	public function findByEmail( string $email ): ?Customer {
		$stmt = DB::pdo()->prepare( "SELECT * FROM customers WHERE email = :e COLLATE NOCASE LIMIT 1" );
		$stmt->execute( [ ':e' => $email ] );
		$row = $stmt->fetch();
		return $row ? Customer::fromRow( $row ) : null;
	}

	public function findByWpUserId( int $userId ): ?Customer {
		$stmt = DB::pdo()->prepare( "SELECT * FROM customers WHERE wp_user_id = :u LIMIT 1" );
		$stmt->execute( [ ':u' => $userId ] );
		$row = $stmt->fetch();
		return $row ? Customer::fromRow( $row ) : null;
	}

	/**
	 * @return Customer[]
	 */
	public function list( ?string $search = null, int $limit = 100, int $offset = 0 ): array {
		$where = '1=1';
		$bind  = [];
		if ( $search !== null && $search !== '' ) {
			$where .= " AND (email LIKE :q OR first_name LIKE :q OR last_name LIKE :q)";
			$bind[':q'] = '%' . $search . '%';
		}
		$stmt = DB::pdo()->prepare(
			"SELECT * FROM customers WHERE $where ORDER BY updated_at DESC LIMIT :lim OFFSET :off"
		);
		foreach ( $bind as $k => $v ) $stmt->bindValue( $k, $v );
		$stmt->bindValue( ':lim', $limit,  \PDO::PARAM_INT );
		$stmt->bindValue( ':off', $offset, \PDO::PARAM_INT );
		$stmt->execute();
		return array_map( [ Customer::class, 'fromRow' ], $stmt->fetchAll() );
	}

	public function count( ?string $search = null ): int {
		$where = '1=1';
		$bind  = [];
		if ( $search !== null && $search !== '' ) {
			$where .= " AND (email LIKE :q OR first_name LIKE :q OR last_name LIKE :q)";
			$bind[':q'] = '%' . $search . '%';
		}
		$stmt = DB::pdo()->prepare( "SELECT COUNT(*) AS c FROM customers WHERE $where" );
		$stmt->execute( $bind );
		return (int) ( $stmt->fetch()['c'] ?? 0 );
	}

	/**
	 * Upsert by email — the import + checkout entry point.
	 *
	 * @param array<string,mixed> $fields   incoming data; ignored keys discarded
	 * @param string              $conflict 'update' (merge) | 'skip' (keep existing) | 'replace' (overwrite all)
	 *
	 * @return array{customer: Customer, action: 'created'|'updated'|'skipped'}
	 */
	public function upsertByEmail( string $email, array $fields, string $conflict = 'update' ): array {
		$email    = trim( $email );
		if ( $email === '' ) throw new \InvalidArgumentException( 'Email is required.' );
		$existing = $this->findByEmail( $email );

		if ( $existing && $conflict === 'skip' ) {
			return [ 'customer' => $existing, 'action' => 'skipped' ];
		}

		if ( $existing ) {
			// 'update' (merge — null fields don't clobber existing)
			// vs 'replace' (overwrite everything explicitly passed).
			$merged = $this->mergeFields( $existing, $fields, $conflict === 'replace' );
			$this->save( $existing->id, $merged );
			return [ 'customer' => $this->findById( $existing->id ), 'action' => 'updated' ];
		}

		$id = $this->insert( $email, $fields );
		return [ 'customer' => $this->findById( $id ), 'action' => 'created' ];
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function insert( string $email, array $fields ): int {
		$row = $this->prepareRow( $fields );
		$pdo = DB::pdo();
		$stmt = $pdo->prepare(
			"INSERT INTO customers (
				uuid, email, first_name, last_name, phone, wp_user_id,
				accepts_marketing,
				address_line1, address_line2, city, state, postal_code, country,
				tags, orders_count, total_spent_cents, last_order_at
			) VALUES (
				:uuid, :email, :first_name, :last_name, :phone, :wp_user_id,
				:accepts_marketing,
				:address_line1, :address_line2, :city, :state, :postal_code, :country,
				:tags, :orders_count, :total_spent_cents, :last_order_at
			)"
		);
		$stmt->execute( array_merge( [
			':uuid'  => wp_generate_uuid4(),
			':email' => $email,
		], $row ) );
		return (int) $pdo->lastInsertId();
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function save( int $id, array $fields ): void {
		$row = $this->prepareRow( $fields );
		$assigns = [];
		foreach ( $row as $k => $_ ) {
			$col = substr( $k, 1 ); // strip ':'
			$assigns[] = "$col = $k";
		}
		$assigns[] = "updated_at = unixepoch()";
		$stmt = DB::pdo()->prepare( "UPDATE customers SET " . implode( ', ', $assigns ) . " WHERE id = :id" );
		$stmt->execute( array_merge( $row, [ ':id' => $id ] ) );
	}

	public function delete( int $id ): void {
		$stmt = DB::pdo()->prepare( "DELETE FROM customers WHERE id = :id" );
		$stmt->execute( [ ':id' => $id ] );
	}

	/**
	 * Increment lifetime stats after a successful order. Idempotent
	 * against the same order_id via the orders table; this just bumps
	 * the denormalized counters.
	 */
	public function recordOrder( int $customerId, int $totalCents, int $occurredAt ): void {
		$stmt = DB::pdo()->prepare(
			"UPDATE customers
			    SET orders_count      = orders_count + 1,
			        total_spent_cents = total_spent_cents + :spent,
			        last_order_at     = MAX(COALESCE(last_order_at, 0), :occ),
			        updated_at        = unixepoch()
			  WHERE id = :id"
		);
		$stmt->execute( [ ':id' => $customerId, ':spent' => $totalCents, ':occ' => $occurredAt ] );
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>  PDO bind params (with leading colons)
	 */
	private function prepareRow( array $fields ): array {
		$pick = function ( string $k ) use ( $fields ) {
			$v = $fields[ $k ] ?? null;
			return $v === '' ? null : $v;
		};
		$tags = $fields['tags'] ?? [];
		if ( is_string( $tags ) ) {
			$tags = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
		}
		return [
			':first_name'        => $pick( 'first_name' ),
			':last_name'         => $pick( 'last_name' ),
			':phone'             => $pick( 'phone' ),
			':wp_user_id'        => isset( $fields['wp_user_id'] ) ? (int) $fields['wp_user_id'] : null,
			':accepts_marketing' => ! empty( $fields['accepts_marketing'] ) ? 1 : 0,
			':address_line1'     => $pick( 'address_line1' ),
			':address_line2'     => $pick( 'address_line2' ),
			':city'              => $pick( 'city' ),
			':state'             => $pick( 'state' ),
			':postal_code'       => $pick( 'postal_code' ),
			':country'           => $pick( 'country' ),
			':tags'              => wp_json_encode( array_values( (array) $tags ) ),
			':orders_count'      => (int) ( $fields['orders_count'] ?? 0 ),
			':total_spent_cents' => (int) ( $fields['total_spent_cents'] ?? 0 ),
			':last_order_at'     => isset( $fields['last_order_at'] ) ? (int) $fields['last_order_at'] : null,
		];
	}

	/**
	 * @param array<string,mixed> $incoming
	 * @return array<string,mixed>
	 */
	private function mergeFields( Customer $existing, array $incoming, bool $replace ): array {
		$base = [
			'first_name'        => $existing->first_name,
			'last_name'         => $existing->last_name,
			'phone'             => $existing->phone,
			'wp_user_id'        => $existing->wp_user_id,
			'accepts_marketing' => $existing->accepts_marketing,
			'address_line1'     => $existing->address_line1,
			'address_line2'     => $existing->address_line2,
			'city'              => $existing->city,
			'state'             => $existing->state,
			'postal_code'       => $existing->postal_code,
			'country'           => $existing->country,
			'tags'              => $existing->tags,
			'orders_count'      => $existing->orders_count,
			'total_spent_cents' => $existing->total_spent_cents,
			'last_order_at'     => $existing->last_order_at,
		];
		if ( $replace ) return array_merge( $base, $incoming );
		// 'update' — only overwrite where incoming has a non-null value.
		foreach ( $incoming as $k => $v ) {
			if ( $v !== null && $v !== '' ) $base[ $k ] = $v;
		}
		return $base;
	}
}
