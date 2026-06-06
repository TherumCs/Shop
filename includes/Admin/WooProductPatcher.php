<?php
/**
 * Counter by Therum — WooProductPatcher.
 *
 * Extracted from AdminController to handle WooCommerce-specific product
 * patching logic. Reduces AdminController from 1015 to ~850 lines.
 *
 * Handles:
 *   - patchWooProduct(): Applies full drawer edits to Woo products
 *   - wcProductToRow(): Converts WC_Product to grid row format
 *   - wcProductDetail(): Converts WC_Product to drawer detail format
 *   - bulkWooOps(): Bulk actions (delete, duplicate, set_status, set)
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class WooProductPatcher {

	/**
	 * Patch a WooCommerce product. Supports scalar fields (title, slug, status,
	 * description) and nested groups (price, inventory, shipping, images, seo).
	 *
	 * @param int $id WooCommerce product ID
	 * @param array<string,mixed> $body Patch data
	 * @return \WP_REST_Response
	 */
	public function patch( int $id, array $body ): \WP_REST_Response {
		$wc = wc_get_product( $id );
		if ( ! $wc instanceof \WC_Product ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'Product not found.' ] ], 404 );
		}
		$ok = [];

		// Scalar fields (title, slug, status, description, short_description)
		$this->patchScalarFields( $wc, $body, $ok );

		// Legacy flat fields (sku, stock_qty, price) for backward-compat with inline grid
		$this->patchLegacyFields( $wc, $body, $ok );

		// Nested groups from drawer (price, inventory, shipping, images, seo)
		$this->patchNestedGroups( $wc, $body, $ok, $id );

		$wc->save();
		return new \WP_REST_Response( [
			'ok'      => true,
			'updated' => $ok,
			'row'     => $this->toRow( $wc ),
		], 200 );
	}

	/**
	 * Convert WC_Product to admin grid row format.
	 *
	 * @return array<string,mixed>
	 */
	public function toRow( \WC_Product $wc ): array {
		$regular      = $this->priceCents( $wc->get_regular_price() );
		$priceCents   = $regular ?? $this->priceCents( $wc->get_price() );
		$compareCents = $this->priceCents( $wc->get_sale_price() ) !== null ? $regular : null;
		$imageId      = (int) $wc->get_image_id();

		return [
			'id'                => $wc->get_id(),
			'uuid'              => 'wc-' . $wc->get_id(),
			'slug'              => $wc->get_slug(),
			'title'             => $wc->get_name(),
			'status'            => $wc->get_status(),
			'has_variants'      => $wc->is_type( 'variable' ) ? 1 : 0,
			'is_shippable'      => $wc->is_virtual() ? 0 : 1,
			'is_digital'        => $wc->is_downloadable() ? 1 : 0,
			'is_pod'            => 0,
			'track_inventory'   => $wc->managing_stock() ? 1 : 0,
			'price'             => $priceCents,
			'compare_at_price'  => $compareCents,
			'cost'              => null,
			'sku'               => $wc->get_sku() ?: null,
			'stock_qty'         => $wc->managing_stock() ? (int) $wc->get_stock_quantity() : null,
			'primary_image_id'  => $imageId ?: null,
			'image_url'         => $imageId ? (string) wp_get_attachment_image_url( $imageId, 'thumbnail' ) : null,
			'created_at'        => $wc->get_date_created() ? $wc->get_date_created()->getTimestamp() : null,
			'updated_at'        => $wc->get_date_modified() ? $wc->get_date_modified()->getTimestamp() : null,
		];
	}

	/**
	 * Convert WC_Product to drawer detail format.
	 *
	 * @return array<string,mixed>
	 */
	public function toDetail( \WC_Product $wc ): array {
		$imageId = (int) $wc->get_image_id();
		$gallery_ids = array_map( 'intval', (array) $wc->get_gallery_image_ids() );

		return [
			'id'       => $wc->get_id(),
			'title'    => $wc->get_name(),
			'slug'     => $wc->get_slug(),
			'status'   => $wc->get_status(),
			'sku'      => $wc->get_sku(),

			'description'       => $wc->get_description(),
			'short_description' => $wc->get_short_description(),

			'price' => [
				'regular'    => $this->priceCents( $wc->get_regular_price() ),
				'sale'       => $this->priceCents( $wc->get_sale_price() ),
				'sale_from'  => $wc->get_date_on_sale_from()?->format( 'Y-m-d' ),
				'sale_to'    => $wc->get_date_on_sale_to()?->format( 'Y-m-d' ),
			],

			'inventory' => [
				'sku'              => $wc->get_sku(),
				'manage_stock'     => $wc->managing_stock(),
				'stock_qty'        => $wc->managing_stock() ? (int) $wc->get_stock_quantity() : null,
				'stock_status'     => $wc->get_stock_status(),
				'backorder'        => $wc->get_backorders(),
				'low_stock_amount' => $wc->get_low_stock_amount() ?: null,
			],

			'shipping' => [
				'weight'           => (float) $wc->get_weight() ?: null,
				'length'           => (float) $wc->get_length() ?: null,
				'width'            => (float) $wc->get_width()  ?: null,
				'height'           => (float) $wc->get_height() ?: null,
				'shipping_class'   => $wc->get_shipping_class_id(),
				'virtual'          => (bool) $wc->is_virtual(),
				'downloadable'     => (bool) $wc->is_downloadable(),
			],

			'images' => [
				'primary' => $imageId ? [ 'id' => $imageId, 'url' => wp_get_attachment_image_url( $imageId, 'large' ) ] : null,
				'gallery' => array_map( function( $id ) {
					return [ 'id' => $id, 'url' => wp_get_attachment_image_url( $id, 'large' ) ];
				}, $gallery_ids ),
			],

			'seo' => [
				'meta_title'       => get_post_meta( $wc->get_id(), '_yoast_wpseo_title',    true ),
				'meta_description' => get_post_meta( $wc->get_id(), '_yoast_wpseo_metadesc', true ),
			],
		];
	}

	/**
	 * Bulk operations on Woo products.
	 *
	 * @param int[] $ids
	 * @param string $action (delete, duplicate, set_status, set)
	 * @param array<string,mixed> $body
	 * @return \WP_REST_Response
	 */
	public function bulkOps( array $ids, string $action, array $body ): \WP_REST_Response {
		if ( ! $ids ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => 'No products selected.' ] ], 400 );
		}

		match ( $action ) {
			'delete'     => $this->bulkDelete( $ids ),
			'duplicate'  => $this->bulkDuplicate( $ids ),
			'set_status' => $this->bulkSetStatus( $ids, (string) ( $body['status'] ?? '' ) ),
			'set'        => $this->bulkSet( $ids, (array) ( $body['fields'] ?? [] ) ),
		};

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	// ─── Helpers ──────────────────────────────────────────────────────────

	private function priceCents( $value ): ?int {
		if ( $value === '' || $value === null ) return null;
		return (int) round( ( (float) $value ) * 100 );
	}

	private function patchScalarFields( \WC_Product $wc, array $body, array &$ok ): void {
		foreach ( [ 'title', 'slug', 'status', 'description', 'short_description' ] as $field ) {
			if ( ! array_key_exists( $field, $body ) ) continue;
			$value = $body[ $field ];
			match ( $field ) {
				'title'             => $wc->set_name( (string) $value ),
				'slug'              => $wc->set_slug( (string) $value ),
				'status'            => $wc->set_status( (string) $value ),
				'description'       => $wc->set_description( (string) $value ),
				'short_description' => $wc->set_short_description( (string) $value ),
			};
			$ok[] = $field;
		}
	}

	private function patchLegacyFields( \WC_Product $wc, array $body, array &$ok ): void {
		if ( array_key_exists( 'sku', $body ) ) {
			$wc->set_sku( (string) $body['sku'] );
			$ok[] = 'sku';
		}
		if ( array_key_exists( 'stock_qty', $body ) ) {
			$v = $body['stock_qty'];
			if ( $v === null || $v === '' ) $wc->set_manage_stock( false );
			else { $wc->set_manage_stock( true ); $wc->set_stock_quantity( (int) $v ); }
			$ok[] = 'stock_qty';
		}
		if ( array_key_exists( 'price', $body ) ) {
			$v = $body['price'];
			if ( ! is_array( $v ) ) {
				$wc->set_regular_price( ( $v === null || $v === '' ) ? '' : number_format( ( (int) $v ) / 100, 2, '.', '' ) );
				$ok[] = 'price';
			}
		}
	}

	private function patchNestedGroups( \WC_Product $wc, array $body, array &$ok, int $productId ): void {
		if ( is_array( $body['price'] ?? null ) ) {
			$this->patchPrice( $wc, $body['price'] );
			$ok[] = 'price';
		}
		if ( is_array( $body['inventory'] ?? null ) ) {
			$this->patchInventory( $wc, $body['inventory'] );
			$ok[] = 'inventory';
		}
		if ( is_array( $body['shipping'] ?? null ) ) {
			$this->patchShipping( $wc, $body['shipping'] );
			$ok[] = 'shipping';
		}
		if ( is_array( $body['images'] ?? null ) ) {
			$this->patchImages( $wc, $body['images'] );
			$ok[] = 'images';
		}
		if ( is_array( $body['seo'] ?? null ) ) {
			$this->patchSeo( $productId, $body['seo'] );
			$ok[] = 'seo';
		}
	}

	private function patchPrice( \WC_Product $wc, array $data ): void {
		if ( array_key_exists( 'regular', $data ) ) {
			$v = $data['regular'];
			$wc->set_regular_price( $v === null ? '' : number_format( ( (int) $v ) / 100, 2, '.', '' ) );
		}
		if ( array_key_exists( 'sale', $data ) ) {
			$v = $data['sale'];
			$wc->set_sale_price( $v === null ? '' : number_format( ( (int) $v ) / 100, 2, '.', '' ) );
		}
		if ( array_key_exists( 'sale_from', $data ) ) $wc->set_date_on_sale_from( $data['sale_from'] ?: null );
		if ( array_key_exists( 'sale_to', $data ) ) $wc->set_date_on_sale_to( $data['sale_to'] ?: null );
	}

	private function patchInventory( \WC_Product $wc, array $data ): void {
		if ( array_key_exists( 'sku', $data ) ) $wc->set_sku( (string) $data['sku'] );
		if ( array_key_exists( 'manage_stock', $data ) ) $wc->set_manage_stock( (bool) $data['manage_stock'] );
		if ( array_key_exists( 'stock_qty', $data ) && $wc->managing_stock() ) $wc->set_stock_quantity( (int) $data['stock_qty'] );
		if ( array_key_exists( 'stock_status', $data ) && $data['stock_status'] ) $wc->set_stock_status( (string) $data['stock_status'] );
		if ( array_key_exists( 'backorder', $data ) && $data['backorder'] !== null ) $wc->set_backorders( (string) $data['backorder'] );
		if ( array_key_exists( 'low_stock_amount', $data ) ) {
			$wc->set_low_stock_amount( $data['low_stock_amount'] === null ? '' : (string) (int) $data['low_stock_amount'] );
		}
	}

	private function patchShipping( \WC_Product $wc, array $data ): void {
		if ( array_key_exists( 'weight', $data ) ) $wc->set_weight( (string) $data['weight'] );
		if ( array_key_exists( 'length', $data ) ) $wc->set_length( (string) $data['length'] );
		if ( array_key_exists( 'width', $data ) ) $wc->set_width( (string) $data['width'] );
		if ( array_key_exists( 'height', $data ) ) $wc->set_height( (string) $data['height'] );
		if ( array_key_exists( 'shipping_class', $data ) ) {
			$wc->set_shipping_class_id( (int) wp_get_term_taxonomy( $data['shipping_class'] ?: 0, 'product_shipping_class' ) );
		}
		if ( array_key_exists( 'virtual', $data ) ) $wc->set_virtual( (bool) $data['virtual'] );
		if ( array_key_exists( 'downloadable', $data ) ) $wc->set_downloadable( (bool) $data['downloadable'] );
	}

	private function patchImages( \WC_Product $wc, array $data ): void {
		if ( isset( $data['primary']['id'] ) ) $wc->set_image_id( (int) $data['primary']['id'] );
		if ( isset( $data['gallery'] ) && is_array( $data['gallery'] ) ) {
			$wc->set_gallery_image_ids( array_filter( array_map( fn( $g ) => (int) ( $g['id'] ?? 0 ), $data['gallery'] ) ) );
		}
	}

	private function patchSeo( int $productId, array $data ): void {
		if ( isset( $data['meta_title'] ) ) update_post_meta( $productId, '_yoast_wpseo_title', (string) $data['meta_title'] );
		if ( isset( $data['meta_description'] ) ) update_post_meta( $productId, '_yoast_wpseo_metadesc', (string) $data['meta_description'] );
	}

	private function bulkDelete( array $ids ): void {
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	private function bulkDuplicate( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( class_exists( '\WC_Admin_Duplicate_Product' ) ) {
				\WC_Admin_Duplicate_Product::duplicate_product( (int) $id );
			}
		}
	}

	private function bulkSetStatus( array $ids, string $status ): void {
		foreach ( $ids as $id ) {
			$wc = wc_get_product( (int) $id );
			if ( $wc ) { $wc->set_status( $status ); $wc->save(); }
		}
	}

	private function bulkSet( array $ids, array $fields ): void {
		foreach ( $ids as $id ) {
			$wc = wc_get_product( (int) $id );
			if ( ! $wc ) continue;
			foreach ( $fields as $field => $value ) {
				match ( $field ) {
					'sku'    => $wc->set_sku( (string) $value ),
					'status' => $wc->set_status( (string) $value ),
				};
			}
			$wc->save();
		}
	}
}
