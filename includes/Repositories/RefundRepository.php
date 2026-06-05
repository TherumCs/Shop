<?php
/**
 * Shop by Therum — RefundRepository.
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\Refund;

if ( ! defined( 'ABSPATH' ) ) exit;

final class RefundRepository {

	public function create(
		int $orderId,
		int $amountMinor,
		string $reason,
		string $initiatedBy = 'admin',
		?int $refundedByUserId = null,
	): Refund {
		$pdo = DB::pdo();
		$uuid = wp_generate_uuid4();
		$pdo->prepare(
			"INSERT INTO refunds (uuid, order_id, amount, reason, status, initiated_by, refunded_by_user_id)
			 VALUES (:u, :o, :a, :r, 'pending', :ib, :uid)"
		)->execute( [
			':u'   => $uuid,
			':o'   => $orderId,
			':a'   => $amountMinor,
			':r'   => $reason,
			':ib'  => $initiatedBy,
			':uid' => $refundedByUserId,
		] );

		$id = (int) $pdo->lastInsertId();
		return $this->findById( $id ) ?? throw new \RuntimeException( 'Refund vanished post-insert' );
	}

	public function findById( int $id, string $currency = 'USD' ): ?Refund {
		$stmt = DB::pdo()->prepare( "SELECT * FROM refunds WHERE id = :i" );
		$stmt->execute( [ ':i' => $id ] );
		$row = $stmt->fetch();
		return $row ? Refund::fromRow( $row, $currency ) : null;
	}

	/** @return Refund[] */
	public function forOrder( int $orderId, string $currency = 'USD' ): array {
		$stmt = DB::pdo()->prepare( "SELECT * FROM refunds WHERE order_id = :o ORDER BY id DESC" );
		$stmt->execute( [ ':o' => $orderId ] );
		return array_map( fn( array $r ): Refund => Refund::fromRow( $r, $currency ), $stmt->fetchAll() );
	}

	public function markComplete(
		int $refundId,
		string $provider,
		string $gatewayRefundId,
	): void {
		DB::pdo()->prepare(
			"UPDATE refunds
			    SET status = 'completed',
			        payment_provider  = :p,
			        gateway_refund_id = :g,
			        completed_at = unixepoch(),
			        updated_at   = unixepoch()
			  WHERE id = :i"
		)->execute( [
			':p' => $provider,
			':g' => $gatewayRefundId,
			':i' => $refundId,
		] );
	}

	public function markFailed( int $refundId, string $reason ): void {
		DB::pdo()->prepare(
			"UPDATE refunds
			    SET status = 'failed',
			        failure_reason = :r,
			        updated_at = unixepoch()
			  WHERE id = :i"
		)->execute( [ ':r' => $reason, ':i' => $refundId ] );
	}

	/**
	 * Insert line allocations for a refund (which order_items got how much).
	 *
	 * @param array<int,array{order_item_id:int,quantity:int,amount:int,restock:string}> $lines
	 */
	public function attachLines( int $refundId, array $lines ): void {
		$stmt = DB::pdo()->prepare(
			"INSERT INTO refund_items (refund_id, order_item_id, quantity, amount, restock)
			 VALUES (:r, :oi, :q, :a, :rs)"
		);
		foreach ( $lines as $line ) {
			$stmt->execute( [
				':r'  => $refundId,
				':oi' => $line['order_item_id'],
				':q'  => $line['quantity'],
				':a'  => $line['amount'],
				':rs' => $line['restock'] ?? 'restock',
			] );
		}
	}

	public function totalRefundedFor( int $orderId ): int {
		$stmt = DB::pdo()->prepare(
			"SELECT COALESCE( SUM(amount), 0 ) AS t
			   FROM refunds
			  WHERE order_id = :o AND status = 'completed'"
		);
		$stmt->execute( [ ':o' => $orderId ] );
		return (int) ( $stmt->fetch()['t'] ?? 0 );
	}
}
