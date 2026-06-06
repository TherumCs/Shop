<?php
/**
 * Counter by Therum — WooProductRepository.
 *
 * Read-in-place adapter for WooCommerce. Returns Product / Variant DTOs
 * sourced from Woo's APIs (wp_posts + postmeta, accessed via wc_get_product()).
 * Stock decrement goes through Woo's own APIs so we don't double-book
 * against any Woo-side cart that may still be in play.
 *
 * Critically, this maps POD-plugin postmeta back onto our schema:
 *
 *   Printful   _printful_product_id     →  pod_product_id
 *              _printful_variant_id     →  pod_variant_id
 *              pod_provider             =  'printful'
 *
 *   Printify   _printify_product_id     →  pod_product_id
 *              _printify_variant_id     →  pod_variant_id
 *              pod_provider             =  'printify'
 *
 *   PodPartner _podpartner_product_id   →  pod_product_id
 *              _podpartner_variant_id   →  pod_variant_id
 *              pod_provider             =  'podpartner'
 *
 *   TapStitch  _tapstitch_product_id    →  pod_product_id
 *              _tapstitch_variant_id    →  pod_variant_id
 *              pod_provider             =  'tapstitch'
 *
 *   PodPluser  _podpluser_product_id    →  pod_product_id
 *              _podpluser_variant_id    →  pod_variant_id
 *              pod_provider             =  'podpluser'
 *
 * Additional providers are pluggable via the `shop_woo_pod_providers`
 * filter — each entry is [ 'slug' => 'foo', 'meta_product' => '_foo_product_id',
 * 'meta_variant' => '_foo_variant_id' ].
 *
 * The vendor link survives intact: orders placed through Shop carry the
 * vendor IDs, so when Shop mirrors the order back to WC_Order, the POD
 * plugins fulfill exactly as they always have.
 */

namespace Counter\Repositories;

use Counter\Models\Product;
use Counter\Models\Variant;
use Counter\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class WooProductRepository implements ProductRepository {

	/**
	 * Request-scoped Product/Variant cache. Cart rendering with N line
	 * items used to hit `wc_get_product()` N times per render pass — the
	 * cart shows, then the floating-button shows, then a REST refresh
	 * shows, easily 3N lookups for the same handful of products in one
	 * request. This memoizes them.
	 *
	 * Cleared at the end of every request implicitly (object lives in
	 * the singleton container). For long-running CLI processes, a future
	 * `clear()` method would let workers flush between iterations.
	 *
	 * @var array<string, ?Product>
	 */
	private array $productCache = [];

	/** @var array<string, ?Variant> */
	private array $variantCache = [];

	/** @var array<int, ?string> */
	private array $podCache = [];

	/**
	 * @return array<int, array{slug:string, meta_product:string, meta_variant:string}>
	 */
	private function podProviders(): array {
		$defaults = [
			[ 'slug' => 'printful',   'meta_product' => '_printful_product_id',   'meta_variant' => '_printful_variant_id' ],
			[ 'slug' => 'printify',   'meta_product' => '_printify_product_id',   'meta_variant' => '_printify_variant_id' ],
			[ 'slug' => 'podpartner', 'meta_product' => '_podpartner_product_id', 'meta_variant' => '_podpartner_variant_id' ],
			[ 'slug' => 'tapstitch',  'meta_product' => '_tapstitch_product_id',  'meta_variant' => '_tapstitch_variant_id' ],
			[ 'slug' => 'podpluser',  'meta_product' => '_podpluser_product_id',  'meta_variant' => '_podpluser_variant_id' ],
		];
		return (array) apply_filters( 'counter_woo_pod_providers', $defaults );
	}

	public function findById( int $id, string $currency = 'USD' ): ?Product {
		$key = $id . '|' . $currency;
		if ( array_key_exists( $key, $this->productCache ) ) {
			return $this->productCache[ $key ];
		}

		if ( ! function_exists( 'wc_get_product' ) ) return $this->productCache[ $key ] = null;

		$wc = wc_get_product( $id );
		if ( ! $wc instanceof \WC_Product ) return $this->productCache[ $key ] = null;

		// Variations are addressed via findVariant(); only return parent products here.
		if ( $wc->is_type( 'variation' ) ) return $this->productCache[ $key ] = null;

		return $this->productCache[ $key ] = $this->productFromWc( $wc, $currency );
	}

	public function findBySlug( string $slug, string $currency = 'USD' ): ?Product {
		global $wpdb;
		$post_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_name = %s AND post_type = 'product' AND post_status IN ( 'publish', 'private' )
			 LIMIT 1",
			$slug
		) );
		return $post_id > 0 ? $this->findById( $post_id, $currency ) : null;
	}

	public function findVariant( int $variantId, string $currency = 'USD' ): ?Variant {
		$key = $variantId . '|' . $currency;
		if ( array_key_exists( $key, $this->variantCache ) ) {
			return $this->variantCache[ $key ];
		}

		if ( ! function_exists( 'wc_get_product' ) ) return $this->variantCache[ $key ] = null;

		$wc = wc_get_product( $variantId );
		if ( ! $wc instanceof \WC_Product_Variation ) return $this->variantCache[ $key ] = null;

		return $this->variantCache[ $key ] = $this->variantFromWc( $wc, $currency );
	}

	public function findVariants( array $variantIds, string $currency = 'USD' ): array {
		if ( ! $variantIds || ! function_exists( 'wc_get_product' ) ) return [];

		$result = [];
		$missing = [];

		// Check cache first
		foreach ( $variantIds as $variantId ) {
			$key = $variantId . '|' . $currency;
			if ( array_key_exists( $key, $this->variantCache ) ) {
				if ( $this->variantCache[ $key ] !== null ) {
					$result[ $variantId ] = $this->variantCache[ $key ];
				}
			} else {
				$missing[] = $variantId;
			}
		}

		// Fetch missing ones
		foreach ( $missing as $variantId ) {
			$variant = $this->findVariant( $variantId, $currency );
			if ( $variant !== null ) {
				$result[ $variantId ] = $variant;
			}
		}

		return $result;
	}

	public function priceFor( Product $product, ?Variant $variant ): ?Money {
		if ( $variant !== null && $variant->price !== null ) {
			return $variant->price;
		}
		return $product->price;
	}

	/**
	 * Stock check delegates to Woo's own knowledge. For variable products
	 * we ask the variation; otherwise we ask the product.
	 */
	public function hasStock( Product $product, ?Variant $variant, int $quantity ): bool {
		if ( ! $product->trackInventory ) return true;

		// Re-fetch from Woo so we see the freshest state — concurrent
		// Woo-side carts may have moved the counter since hydration.
		if ( $product->hasVariants ) {
			if ( $variant === null ) return false;
			$wc = function_exists( 'wc_get_product' ) ? wc_get_product( $variant->id ) : null;
			if ( ! $wc instanceof \WC_Product_Variation ) return false;
			return $wc->has_enough_stock( $quantity );
		}

		$wc = function_exists( 'wc_get_product' ) ? wc_get_product( $product->id ) : null;
		if ( ! $wc instanceof \WC_Product ) return false;
		return $wc->has_enough_stock( $quantity );
	}

	// ─── Translators ─────────────────────────────────────────────────────

	private function productFromWc( \WC_Product $wc, string $currency ): Product {
		$is_variable    = $wc->is_type( 'variable' );
		$gallery_ids    = array_map( 'intval', (array) $wc->get_gallery_image_ids() );
		$primary_image  = (int) $wc->get_image_id();

		// Capability flags — translate Woo's product type system into ours.
		$is_shippable    = $wc->needs_shipping();
		$is_digital      = $wc->is_virtual() || $wc->is_downloadable();
		$track_inventory = $wc->managing_stock();
		$is_pod          = $this->detectPodProvider( $wc->get_id() ) !== null;

		$price       = $this->priceCents( $wc->get_price() );
		$compare_at  = $this->priceCents( $wc->get_regular_price() );
		// Only treat regular_price as "compare-at" when there's a sale below it.
		$compare_at  = ( $compare_at !== null && $price !== null && $compare_at > $price ) ? $compare_at : null;

		return new Product(
			id:                $wc->get_id(),
			uuid:              'wc-' . $wc->get_id(),
			slug:              (string) $wc->get_slug(),
			title:             (string) $wc->get_name(),
			description:       (string) $wc->get_description(),
			shortDescription:  (string) $wc->get_short_description(),
			status:            $this->mapStatus( (string) $wc->get_status() ),
			authorId:          $this->postAuthor( $wc->get_id() ),
			createdAt:         $this->dateToTs( $wc->get_date_created() ),
			updatedAt:         $this->dateToTs( $wc->get_date_modified() ),
			publishedAt:       $this->dateToTs( $wc->get_date_created() ),

			hasVariants:       $is_variable,
			isShippable:       $is_shippable,
			isDigital:         $is_digital,
			isPod:             $is_pod,
			trackInventory:    $track_inventory,

			price:             $price !== null ? Money::cents( $price, $currency ) : null,
			compareAtPrice:    $compare_at !== null ? Money::cents( $compare_at, $currency ) : null,
			cost:              null, // Woo doesn't track cost natively; POD plugins sometimes do
			sku:               $wc->get_sku() !== '' ? $wc->get_sku() : null,
			stockQty:          $track_inventory ? (int) $wc->get_stock_quantity() : null,

			weight:            $wc->get_weight() !== '' ? (float) $wc->get_weight() : null,
			length:            $wc->get_length() !== '' ? (float) $wc->get_length() : null,
			width:             $wc->get_width()  !== '' ? (float) $wc->get_width()  : null,
			height:            $wc->get_height() !== '' ? (float) $wc->get_height() : null,
			weightUnit:        (string) get_option( 'woocommerce_weight_unit', 'g' ),
			dimensionUnit:     (string) get_option( 'woocommerce_dimension_unit', 'cm' ),

			primaryImageId:    $primary_image > 0 ? $primary_image : null,
			galleryImageIds:   $gallery_ids,

			meta:              [
				'_source'  => 'woo',
				'wc_type'  => $wc->get_type(),
			],
		);
	}

	private function variantFromWc( \WC_Product_Variation $wc, string $currency ): Variant {
		$parent_id = (int) $wc->get_parent_id();
		$pod       = $this->detectPodProvider( $wc->get_id(), $parent_id );

		$price       = $this->priceCents( $wc->get_price() );
		$compare_at  = $this->priceCents( $wc->get_regular_price() );
		$compare_at  = ( $compare_at !== null && $price !== null && $compare_at > $price ) ? $compare_at : null;

		// Variation attributes: keys come back as taxonomy slugs (`pa_color`);
		// strip the prefix and snapshot the values into meta.options so
		// rendering can surface them as "Red · Large" without a second query.
		$options = [];
		foreach ( (array) $wc->get_variation_attributes() as $key => $value ) {
			$clean = ltrim( str_replace( 'attribute_', '', (string) $key ), '_' );
			if ( str_starts_with( $clean, 'pa_' ) ) $clean = substr( $clean, 3 );
			$options[ $clean ] = (string) $value;
		}

		return new Variant(
			id:              $wc->get_id(),
			uuid:            'wc-' . $wc->get_id(),
			productId:       $parent_id,
			sku:             $wc->get_sku() !== '' ? $wc->get_sku() : null,
			position:        (int) $wc->get_menu_order(),
			enabled:         $wc->is_purchasable(),

			price:           $price !== null ? Money::cents( $price, $currency ) : null,
			compareAtPrice:  $compare_at !== null ? Money::cents( $compare_at, $currency ) : null,
			cost:            null,
			stockQty:        $wc->managing_stock() ? (int) $wc->get_stock_quantity() : null,

			weight:          $wc->get_weight() !== '' ? (float) $wc->get_weight() : null,
			length:          $wc->get_length() !== '' ? (float) $wc->get_length() : null,
			width:           $wc->get_width()  !== '' ? (float) $wc->get_width()  : null,
			height:          $wc->get_height() !== '' ? (float) $wc->get_height() : null,

			imageId:         (int) $wc->get_image_id() ?: null,

			podProvider:     $pod['provider'] ?? null,
			podProductId:    $pod['product_id'] ?? null,
			podVariantId:    $pod['variant_id'] ?? null,

			meta:            [
				'_source'  => 'woo',
				'options'  => $options,
			],
		);
	}

	// ─── POD provider detection ──────────────────────────────────────────

	/**
	 * Returns { provider, product_id, variant_id } if a known POD plugin
	 * has tagged this product (or its parent), else null.
	 *
	 * @return array{provider:string, product_id:?string, variant_id:?string}|null
	 */
	private function detectPodProvider( int $itemId, ?int $parentId = null ): ?array {
		foreach ( $this->podProviders() as $p ) {
			$pid_meta = $p['meta_product'];
			$vid_meta = $p['meta_variant'];

			$pid = (string) get_post_meta( $itemId, $pid_meta, true );
			$vid = (string) get_post_meta( $itemId, $vid_meta, true );

			// Variations frequently inherit product-level pod_id from parent.
			if ( $pid === '' && $parentId ) {
				$pid = (string) get_post_meta( $parentId, $pid_meta, true );
			}

			if ( $pid !== '' || $vid !== '' ) {
				return [
					'provider'   => $p['slug'],
					'product_id' => $pid !== '' ? $pid : null,
					'variant_id' => $vid !== '' ? $vid : null,
				];
			}
		}
		return null;
	}

	// ─── Misc helpers ────────────────────────────────────────────────────

	private function priceCents( $value ): ?int {
		if ( $value === '' || $value === null ) return null;
		return (int) round( ( (float) $value ) * 100 );
	}

	private function mapStatus( string $woo_status ): string {
		return match ( $woo_status ) {
			'publish' => 'active',
			'draft', 'pending', 'auto-draft' => 'draft',
			'private', 'future' => 'archived',
			'trash' => 'archived',
			default => 'draft',
		};
	}

	private function postAuthor( int $post_id ): ?int {
		$author = (int) get_post_field( 'post_author', $post_id );
		return $author > 0 ? $author : null;
	}

	private function dateToTs( $date ): int {
		if ( $date instanceof \WC_DateTime ) return $date->getTimestamp();
		if ( $date instanceof \DateTimeInterface ) return $date->getTimestamp();
		return 0;
	}
}
