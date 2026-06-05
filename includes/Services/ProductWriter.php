<?php
/**
 * Shop by Therum — ProductWriter.
 *
 * Inserts PreviewProducts into our SQLite as real Products. Used by:
 *
 *   - Importer confirmation flow (admin reviews preview → bulk commits)
 *   - Future: Nexus push (`POST /shop/v1/sync/product`) — converts a
 *     Nexus product payload into a PreviewProduct, then writes here
 *
 * Always writes to our SQLite, regardless of `shop_product_source`.
 * Imports always go to native, even in Woo mode — the customer ends up
 * with hybrid catalog (some products in Woo, some in our SQLite). When
 * Woo mode is active, the Woo repository transparently falls back to
 * the native repo for IDs it doesn't recognize (planned, follow-up
 * chunk).
 *
 * Image sideloading: imageUrls are sideloaded to the WP media library
 * via media_sideload_image(). Attachments are stored against the new
 * product as primaryImageId + galleryImageIds.
 */

namespace Shop\Services;

use Shop\DB;
use Shop\Importers\PreviewProduct;
use Shop\Importers\PreviewVariant;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductWriter {

	/**
	 * Insert a single PreviewProduct. Returns the new product id.
	 */
	public function insert( PreviewProduct $preview ): int {
		return DB::tx( function ( \PDO $pdo ) use ( $preview ): int {
			$uuid = wp_generate_uuid4();
			$slug = $this->uniqueSlug( $preview->title );
			$has_variants = count( $preview->variants ) > 0;

			$primary_id = null;
			$gallery_ids = [];
			foreach ( $preview->imageUrls as $i => $url ) {
				$id = $this->sideload( $url, $preview->title );
				if ( $id === null ) continue;
				if ( $primary_id === null ) $primary_id = $id;
				else                        $gallery_ids[] = $id;
			}

			$pdo->prepare(
				"INSERT INTO products (
					uuid, slug, title, description, status,
					has_variants, is_shippable, is_digital, is_pod, track_inventory,
					price, sku, stock_qty,
					primary_image_id, gallery_image_ids,
					meta, created_at, updated_at
				) VALUES (
					:uuid, :slug, :title, :desc, 'draft',
					:hv, 1, 0, :pod, :ti,
					:price, :sku, :stock,
					:pimg, :gimg,
					:meta, unixepoch(), unixepoch()
				)"
			)->execute( [
				':uuid'  => $uuid,
				':slug'  => $slug,
				':title' => $preview->title,
				':desc'  => $preview->description,
				':hv'    => $has_variants ? 1 : 0,
				':pod'   => $this->hasPodSource( $preview ) ? 1 : 0,
				':ti'    => $preview->stockQty !== null ? 1 : 0,
				':price' => $has_variants ? null : ( $preview->price?->minor ),
				':sku'   => $has_variants ? null : $preview->sku,
				':stock' => $preview->stockQty,
				':pimg'  => $primary_id,
				':gimg'  => $gallery_ids ? wp_json_encode( $gallery_ids ) : null,
				':meta'  => wp_json_encode( [
					'_import' => [
						'source_ref' => $preview->sourceRef,
						'confidence' => $preview->confidence,
					],
				] ),
			] );
			$product_id = (int) $pdo->lastInsertId();

			foreach ( $preview->variants as $position => $v ) {
				$this->insertVariant( $pdo, $product_id, $position, $v );
			}

			return $product_id;
		} );
	}

	/**
	 * Bulk insert. Returns the list of new product ids in the same order
	 * as the input. Wraps each insert in its own transaction so a single
	 * bad row doesn't roll back the whole batch.
	 *
	 * @param PreviewProduct[] $previews
	 * @return int[]
	 */
	public function bulk( array $previews ): array {
		$ids = [];
		foreach ( $previews as $p ) {
			try {
				$ids[] = $this->insert( $p );
			} catch ( \Throwable $e ) {
				error_log( 'Shop\ProductWriter::bulk failed on "' . $p->title . '": ' . $e->getMessage() );
				$ids[] = 0;
			}
		}
		return $ids;
	}

	private function insertVariant( \PDO $pdo, int $productId, int $position, PreviewVariant $v ): void {
		$image_id = null;
		if ( $v->imageUrl !== null ) {
			$image_id = $this->sideload( $v->imageUrl, 'Variant ' . $productId );
		}

		$pdo->prepare(
			"INSERT INTO product_variants (
				uuid, product_id, sku, position, enabled,
				price, stock_qty,
				image_id,
				pod_provider, pod_product_id, pod_variant_id,
				meta
			) VALUES (
				:uuid, :pid, :sku, :pos, 1,
				:price, :stock,
				:img,
				:pp, :ppid, :pvid,
				:meta
			)"
		)->execute( [
			':uuid'  => wp_generate_uuid4(),
			':pid'   => $productId,
			':sku'   => $v->sku,
			':pos'   => $position,
			':price' => $v->price?->minor,
			':stock' => $v->stockQty,
			':img'   => $image_id,
			':pp'    => $v->podProvider,
			':ppid'  => $v->podProductId,
			':pvid'  => $v->podVariantId,
			':meta'  => wp_json_encode( [ 'options' => $v->options ] ),
		] );
	}

	private function hasPodSource( PreviewProduct $p ): bool {
		foreach ( $p->variants as $v ) {
			if ( $v->podProvider !== null ) return true;
		}
		return false;
	}

	private function sideload( string $url, string $context ): ?int {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$id = media_sideload_image( $url, 0, $context, 'id' );
		return is_wp_error( $id ) || ! is_int( $id ) ? null : $id;
	}

	private function uniqueSlug( string $title ): string {
		$base = sanitize_title( $title );
		if ( $base === '' ) $base = 'product-' . substr( wp_generate_uuid4(), 0, 8 );

		$stmt = DB::pdo()->prepare( "SELECT 1 FROM products WHERE slug = :s" );
		$slug = $base;
		$n    = 1;
		while ( true ) {
			$stmt->execute( [ ':s' => $slug ] );
			if ( $stmt->fetch() === false ) return $slug;
			$slug = $base . '-' . ( ++$n );
		}
	}
}
