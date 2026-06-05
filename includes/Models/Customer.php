<?php
/**
 * Shop by Therum — Customer DTO.
 *
 * The store-side identity layer. Optionally linked to a WP user row
 * (`wp_user_id`) but doesn't require one — Studio merchants want to
 * track guest checkouts and email-only newsletter signups without
 * minting WP user accounts for everyone.
 *
 * Lifetime stats (`orders_count`, `total_spent`, `last_order_at`) are
 * denormalized — updated on every checkout success and reconciled on a
 * nightly job. Keeping them on the row beats joining orders/items at
 * read time, and the spreadsheet admin sorts on them constantly.
 *
 * Address is the *default* shipping/billing — order rows still snapshot
 * the address used at checkout (addresses change; orders shouldn't).
 */

namespace Shop\Models;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Customer {

	public function __construct(
		public readonly int     $id,
		public readonly string  $uuid,
		public readonly string  $email,
		public readonly ?string $first_name,
		public readonly ?string $last_name,
		public readonly ?string $phone,
		public readonly ?int    $wp_user_id,
		public readonly bool    $accepts_marketing,
		public readonly ?string $address_line1,
		public readonly ?string $address_line2,
		public readonly ?string $city,
		public readonly ?string $state,
		public readonly ?string $postal_code,
		public readonly ?string $country,
		/** @var string[] */
		public readonly array   $tags,
		public readonly int     $orders_count,
		public readonly int     $total_spent_cents,
		public readonly ?int    $last_order_at,
		public readonly int     $created_at,
		public readonly int     $updated_at,
	) {}

	public function fullName(): string {
		return trim( ( $this->first_name ?? '' ) . ' ' . ( $this->last_name ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function fromRow( array $row ): self {
		return new self(
			id:               (int)    $row['id'],
			uuid:             (string) ( $row['uuid'] ?? '' ),
			email:            (string) ( $row['email'] ?? '' ),
			first_name:       isset( $row['first_name'] ) ? (string) $row['first_name'] : null,
			last_name:        isset( $row['last_name'] )  ? (string) $row['last_name']  : null,
			phone:            isset( $row['phone'] )      ? (string) $row['phone']      : null,
			wp_user_id:       isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id']    : null,
			accepts_marketing:! empty( $row['accepts_marketing'] ),
			address_line1:    isset( $row['address_line1'] ) ? (string) $row['address_line1'] : null,
			address_line2:    isset( $row['address_line2'] ) ? (string) $row['address_line2'] : null,
			city:             isset( $row['city'] )          ? (string) $row['city']          : null,
			state:            isset( $row['state'] )         ? (string) $row['state']         : null,
			postal_code:      isset( $row['postal_code'] )   ? (string) $row['postal_code']   : null,
			country:          isset( $row['country'] )       ? (string) $row['country']       : null,
			tags:             $row['tags'] ? json_decode( (string) $row['tags'], true ) ?: [] : [],
			orders_count:     (int) ( $row['orders_count'] ?? 0 ),
			total_spent_cents:(int) ( $row['total_spent_cents'] ?? 0 ),
			last_order_at:    isset( $row['last_order_at'] ) ? (int) $row['last_order_at'] : null,
			created_at:       (int) ( $row['created_at'] ?? 0 ),
			updated_at:       (int) ( $row['updated_at'] ?? 0 ),
		);
	}
}
