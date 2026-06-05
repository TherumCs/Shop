<?php
/**
 * Shop by Therum — NativeProductRepository.
 *
 * Reads products from our own SQLite tables (`products`, `product_variants`).
 * The "native" path — products created via Shop's admin UI or pushed in by
 * Nexus from connected vendors. Fast, lean, no Woo dependency.
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\Product;
use Shop\Models\Variant;
use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class NativeProductRepository implements ProductRepository {

	public function findById( int $id, string $currency = 'USD' ): ?Product {
		$stmt = DB::pdo()->prepare( "SELECT * FROM products WHERE id = :i" );
		$stmt->execute( [ ':i' => $id ] );
		$row = $stmt->fetch();
		return $row ? Product::fromRow( $row, $currency ) : null;
	}

	public function findBySlug( string $slug, string $currency = 'USD' ): ?Product {
		$stmt = DB::pdo()->prepare( "SELECT * FROM products WHERE slug = :s" );
		$stmt->execute( [ ':s' => $slug ] );
		$row = $stmt->fetch();
		return $row ? Product::fromRow( $row, $currency ) : null;
	}

	public function findVariant( int $variantId, string $currency = 'USD' ): ?Variant {
		$stmt = DB::pdo()->prepare( "SELECT * FROM product_variants WHERE id = :i" );
		$stmt->execute( [ ':i' => $variantId ] );
		$row = $stmt->fetch();
		return $row ? Variant::fromRow( $row, $currency ) : null;
	}

	public function priceFor( Product $product, ?Variant $variant ): ?Money {
		if ( $variant !== null && $variant->price !== null ) {
			return $variant->price;
		}
		return $product->price;
	}

	public function hasStock( Product $product, ?Variant $variant, int $quantity ): bool {
		if ( ! $product->trackInventory ) return true;

		if ( $product->hasVariants ) {
			if ( $variant === null )            return false;
			if ( $variant->stockQty === null )  return true;
			return $variant->stockQty >= $quantity;
		}

		if ( $product->stockQty === null ) return true;
		return $product->stockQty >= $quantity;
	}
}
