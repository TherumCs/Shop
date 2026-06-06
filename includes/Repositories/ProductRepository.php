<?php
/**
 * Counter by Therum — ProductRepository contract.
 *
 * Catalogs live in different places per install:
 *
 *   Native — products live in our SQLite (`products`, `product_variants`).
 *   Woo    — products live in Woo's wp_posts + postmeta. Used by stores
 *            that have existing Woo + POD-plugin setups and want our
 *            cart/checkout experience without migrating product data.
 *
 * Both implementations sit behind this interface. Services type-hint
 * against `ProductRepository` and never touch a concrete class. The
 * container picks the impl based on the `shop_product_source` setting.
 *
 * Migration is one-way: Native is the long-term home. Woo is a Phase-1
 * convenience until Nexus takes over vendor sync.
 */

namespace Counter\Repositories;

use Counter\Models\Product;
use Counter\Models\Variant;
use Counter\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

interface ProductRepository {

	public function findById( int $id, string $currency = 'USD' ): ?Product;

	public function findBySlug( string $slug, string $currency = 'USD' ): ?Product;

	public function findVariant( int $variantId, string $currency = 'USD' ): ?Variant;

	/**
	 * Batch-fetch variants by ID. Avoids N+1 when loading many variants.
	 *
	 * @param int[] $variantIds
	 * @return Variant[]  Keyed by variant ID
	 */
	public function findVariants( array $variantIds, string $currency = 'USD' ): array;

	/**
	 * Resolve the effective price for a (product, variant) pair. Variant
	 * price wins if set; falls back to product price; returns null if
	 * neither is priced.
	 */
	public function priceFor( Product $product, ?Variant $variant ): ?Money;

	/**
	 * Check whether (product, variant) has stock for `quantity`. Honors
	 * the trackInventory flag: false = always purchasable.
	 */
	public function hasStock( Product $product, ?Variant $variant, int $quantity ): bool;
}
