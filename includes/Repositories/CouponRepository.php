<?php
/**
 * Shop by Therum — CouponRepository.
 *
 * SQL for coupons + coupon_redemptions. CouponService is the only caller.
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\Coupon;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CouponRepository {

	public function findById( int $id, string $currency = 'USD' ): ?Coupon {
		$stmt = DB::pdo()->prepare( "SELECT * FROM coupons WHERE id = :i" );
		$stmt->execute( [ ':i' => $id ] );
		$row = $stmt->fetch();
		return $row ? Coupon::fromRow( $row, $currency ) : null;
	}

	public function findByCode( string $code, string $currency = 'USD' ): ?Coupon {
		$stmt = DB::pdo()->prepare( "SELECT * FROM coupons WHERE code = :c COLLATE NOCASE" );
		$stmt->execute( [ ':c' => $code ] );
		$row = $stmt->fetch();
		return $row ? Coupon::fromRow( $row, $currency ) : null;
	}

	/**
	 * @return Coupon[]
	 */
	public function activeAutoApply( string $currency = 'USD' ): array {
		$stmt = DB::pdo()->query(
			"SELECT * FROM coupons
			  WHERE code IS NULL
			    AND status = 'active'
			    AND ( date_starts IS NULL OR date_starts <= unixepoch() )
			    AND ( date_expires IS NULL OR date_expires >= unixepoch() )"
		);
		return array_map( fn( array $r ): Coupon => Coupon::fromRow( $r, $currency ), $stmt->fetchAll() );
	}

	public function bumpUsage( int $couponId ): void {
		DB::pdo()->prepare(
			"UPDATE coupons SET usage_count = usage_count + 1, updated_at = unixepoch() WHERE id = :i"
		)->execute( [ ':i' => $couponId ] );
	}

	public function decUsage( int $couponId ): void {
		DB::pdo()->prepare(
			"UPDATE coupons SET usage_count = MAX( 0, usage_count - 1 ), updated_at = unixepoch() WHERE id = :i"
		)->execute( [ ':i' => $couponId ] );
	}

	public function recordRedemption(
		int $couponId,
		int $orderId,
		?int $userId,
		?string $email,
		int $amountMinor,
	): void {
		DB::pdo()->prepare(
			"INSERT INTO coupon_redemptions (coupon_id, order_id, user_id, email, amount)
			 VALUES (:c, :o, :u, :e, :a)"
		)->execute( [
			':c' => $couponId,
			':o' => $orderId,
			':u' => $userId,
			':e' => $email,
			':a' => $amountMinor,
		] );
	}

	public function releaseRedemption( int $couponId, int $orderId ): void {
		DB::pdo()->prepare(
			"UPDATE coupon_redemptions
			    SET released_at = unixepoch()
			  WHERE coupon_id = :c AND order_id = :o AND released_at IS NULL"
		)->execute( [ ':c' => $couponId, ':o' => $orderId ] );
	}

	public function usageCountForCustomer( int $couponId, ?int $userId, ?string $email ): int {
		$pdo = DB::pdo();
		$where = 'coupon_id = :c AND released_at IS NULL';
		$bind  = [ ':c' => $couponId ];
		if ( $userId !== null ) {
			$where .= ' AND user_id = :u';
			$bind[':u'] = $userId;
		} elseif ( $email !== null ) {
			$where .= ' AND email = :e COLLATE NOCASE';
			$bind[':e'] = $email;
		} else {
			return 0;
		}
		$stmt = $pdo->prepare( "SELECT COUNT(*) AS c FROM coupon_redemptions WHERE $where" );
		$stmt->execute( $bind );
		return (int) ( $stmt->fetch()['c'] ?? 0 );
	}
}
