<?php
/**
 * Shop by Therum — CartRepository.
 *
 * Owns all SQL for cart/session reads and writes. CartService talks to this
 * repository — it never touches PDO directly. This is the only place
 * sessions / session_items SQL lives.
 *
 * Why a separate repo (vs. SQL inside CartService): keeps the service
 * focused on rules + orchestration, makes mocking trivial in tests, and
 * means the SQLite → Postgres path (if it ever happens) only touches one
 * class.
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\Cart;
use Shop\Models\CartItem;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartRepository {

	/**
	 * Find an open cart by its cookie token. Returns null if not found.
	 * Open = status in ('cart','checkout','pending') and not expired.
	 */
	public function findByToken( string $token ): ?Cart {
		$pdo = DB::pdo();

		$row = $pdo->prepare( "SELECT * FROM sessions WHERE token = :t" );
		$row->execute( [ ':t' => $token ] );
		$session = $row->fetch();
		if ( ! $session ) return null;

		return $this->hydrate( $session );
	}

	public function findById( int $id ): ?Cart {
		$pdo = DB::pdo();
		$row = $pdo->prepare( "SELECT * FROM sessions WHERE id = :i" );
		$row->execute( [ ':i' => $id ] );
		$session = $row->fetch();
		if ( ! $session ) return null;
		return $this->hydrate( $session );
	}

	/**
	 * Create a fresh cart session. Returns the new Cart.
	 */
	public function create( string $token, string $currency = 'USD' ): Cart {
		$pdo = DB::pdo();
		$pdo->prepare(
			"INSERT INTO sessions (token, currency, status, created_at, updated_at)
			 VALUES (:t, :c, 'cart', unixepoch(), unixepoch())"
		)->execute( [ ':t' => $token, ':c' => $currency ] );

		$id   = (int) $pdo->lastInsertId();
		$cart = $this->findById( $id );
		if ( $cart === null ) {
			throw new \RuntimeException( 'CartRepository::create — could not re-fetch just-inserted session' );
		}
		return $cart;
	}

	/**
	 * Find existing item that matches (sessionId, productId, variantId).
	 * Used to merge duplicate add-to-cart calls into a single line.
	 */
	public function findItem( int $sessionId, int $productId, ?int $variantId ): ?CartItem {
		$pdo = DB::pdo();
		$sql = "SELECT * FROM session_items
		         WHERE session_id = :s AND product_id = :p
		         AND " . ( $variantId === null ? 'variant_id IS NULL' : 'variant_id = :v' );
		$stmt = $pdo->prepare( $sql );
		$bind = [ ':s' => $sessionId, ':p' => $productId ];
		if ( $variantId !== null ) $bind[':v'] = $variantId;
		$stmt->execute( $bind );
		$row = $stmt->fetch();
		if ( ! $row ) return null;
		return CartItem::fromRow( $row );
	}

	/**
	 * Insert a new line. Returns the new CartItem.
	 */
	public function insertItem( int $sessionId, int $productId, ?int $variantId, int $quantity, int $unitPriceMinor ): CartItem {
		$pdo  = DB::pdo();
		$line = $unitPriceMinor * $quantity;

		$pdo->prepare(
			"INSERT INTO session_items (session_id, product_id, variant_id, quantity, unit_price, line_total)
			 VALUES (:s, :p, :v, :q, :u, :l)"
		)->execute( [
			':s' => $sessionId,
			':p' => $productId,
			':v' => $variantId,
			':q' => $quantity,
			':u' => $unitPriceMinor,
			':l' => $line,
		] );

		$id = (int) $pdo->lastInsertId();
		$row = $pdo->prepare( "SELECT * FROM session_items WHERE id = :i" );
		$row->execute( [ ':i' => $id ] );
		$out = $row->fetch();
		if ( ! $out ) {
			throw new \RuntimeException( 'CartRepository::insertItem — could not re-fetch just-inserted item' );
		}
		return CartItem::fromRow( $out );
	}

	/**
	 * Set a line's quantity, recomputing line_total = qty × unit_price.
	 * Returns the updated CartItem, or null if it doesn't exist.
	 */
	public function setItemQuantity( int $itemId, int $quantity ): ?CartItem {
		$pdo = DB::pdo();
		$pdo->prepare(
			"UPDATE session_items
			    SET quantity = :q, line_total = quantity * unit_price
			  WHERE id = :i"
		)->execute( [ ':q' => $quantity, ':i' => $itemId ] );

		// Recompute line_total in a second pass — SQLite can't reference
		// the new value of `quantity` mid-UPDATE.
		$pdo->prepare(
			"UPDATE session_items SET line_total = quantity * unit_price WHERE id = :i"
		)->execute( [ ':i' => $itemId ] );

		$row = $pdo->prepare( "SELECT * FROM session_items WHERE id = :i" );
		$row->execute( [ ':i' => $itemId ] );
		$out = $row->fetch();
		return $out ? CartItem::fromRow( $out ) : null;
	}

	public function deleteItem( int $itemId ): void {
		DB::pdo()->prepare( "DELETE FROM session_items WHERE id = :i" )
			->execute( [ ':i' => $itemId ] );
	}

	/**
	 * Persist computed totals back to the sessions row. Called after every
	 * pipeline run so the row is always in sync with its items.
	 */
	public function updateTotals(
		int $sessionId,
		int $subtotal,
		int $discountTotal,
		int $shippingTotal,
		int $taxTotal,
		int $grandTotal,
	): void {
		DB::pdo()->prepare(
			"UPDATE sessions
			    SET subtotal       = :s,
			        discount_total = :d,
			        shipping_total = :sh,
			        tax_total      = :tx,
			        grand_total    = :g,
			        updated_at     = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':s'  => $subtotal,
			':d'  => $discountTotal,
			':sh' => $shippingTotal,
			':tx' => $taxTotal,
			':g'  => $grandTotal,
			':i'  => $sessionId,
		] );
	}

	/**
	 * Load all line items for a session, ordered by id (insertion order).
	 *
	 * @return CartItem[]
	 */
	public function itemsFor( int $sessionId, string $currency = 'USD' ): array {
		$stmt = DB::pdo()->prepare(
			"SELECT * FROM session_items WHERE session_id = :s ORDER BY id ASC"
		);
		$stmt->execute( [ ':s' => $sessionId ] );
		$rows = $stmt->fetchAll();
		return array_map( fn( array $r ): CartItem => CartItem::fromRow( $r, $currency ), $rows );
	}

	/**
	 * @param array<string,mixed> $sessionRow
	 */
	private function hydrate( array $sessionRow ): Cart {
		$items = $this->itemsFor( (int) $sessionRow['id'], (string) $sessionRow['currency'] );
		return Cart::fromRow( $sessionRow, $items );
	}
}
