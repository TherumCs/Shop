<?php
/**
 * Shop by Therum — AttributeRepository.
 *
 * Reads the attribute system: global attributes (Color, Size), their
 * values, and per-product / per-variant linkages.
 *
 * Two main consumers:
 *   - Product detail page (renders variant pickers)
 *   - VariantMatcher (resolves a selected option set → variant_id)
 *
 * For Woo-source mode, attribute reads still flow through Woo's
 * variation API in WooProductRepository; this repo is for our native
 * catalog.
 */

namespace Shop\Repositories;

use Shop\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AttributeRepository {

	/**
	 * All attributes used for variants on a given product, with their
	 * available values. Output shape:
	 *
	 *   [
	 *     'color' => [
	 *       'id' => 1, 'name' => 'Color', 'type' => 'select',
	 *       'values' => [
	 *         [ 'id' => 5, 'slug' => 'red', 'value' => 'Red', 'color_hex' => '#e83b3b', 'image_id' => null ],
	 *         ...
	 *       ],
	 *     ],
	 *     'size' => [ ... ],
	 *   ]
	 *
	 * @return array<string, array{id:int,name:string,type:string,values:array<int,array<string,mixed>>}>
	 */
	public function variantAttributesFor( int $productId ): array {
		$pdo = DB::pdo();
		$stmt = $pdo->prepare(
			"SELECT a.id, a.slug, a.name, a.type, a.position
			   FROM product_attributes pa
			   JOIN attributes a ON a.id = pa.attribute_id
			  WHERE pa.product_id = :p AND pa.used_for_variants = 1
			  ORDER BY pa.position ASC, a.position ASC"
		);
		$stmt->execute( [ ':p' => $productId ] );
		$attributes = $stmt->fetchAll();

		// Only values that at least one variant of this product actually carries.
		$valuesStmt = $pdo->prepare(
			"SELECT DISTINCT av.id, av.attribute_id, av.slug, av.value, av.color_hex, av.image_id, av.position
			   FROM attribute_values av
			   JOIN variant_attribute_values vav ON vav.attribute_value_id = av.id
			   JOIN product_variants pv ON pv.id = vav.variant_id
			  WHERE pv.product_id = :p AND av.attribute_id = :a
			  ORDER BY av.position ASC, av.id ASC"
		);

		$out = [];
		foreach ( $attributes as $a ) {
			$valuesStmt->execute( [ ':p' => $productId, ':a' => $a['id'] ] );
			$values = $valuesStmt->fetchAll();
			$out[ $a['slug'] ] = [
				'id'     => (int) $a['id'],
				'name'   => (string) $a['name'],
				'type'   => (string) $a['type'],
				'values' => array_map( fn( array $v ): array => [
					'id'        => (int) $v['id'],
					'slug'      => (string) $v['slug'],
					'value'     => (string) $v['value'],
					'color_hex' => $v['color_hex']  ?: null,
					'image_id'  => $v['image_id']   ? (int) $v['image_id'] : null,
				], $values ),
			];
		}
		return $out;
	}

	/**
	 * Resolve a variant given an option selection (attribute_slug => value_slug).
	 *
	 * Strategy: find the variant whose attribute_value_id set is a superset of
	 * the requested selection. Done with a single grouped query rather than
	 * iterating in PHP.
	 *
	 * @param array<string,string> $selection  e.g. [ 'color' => 'red', 'size' => 'lg' ]
	 */
	public function matchVariant( int $productId, array $selection ): ?int {
		if ( ! $selection ) return null;

		// First resolve slugs → attribute_value_ids
		$pdo = DB::pdo();
		$value_ids = [];
		foreach ( $selection as $attr_slug => $value_slug ) {
			$stmt = $pdo->prepare(
				"SELECT av.id
				   FROM attribute_values av
				   JOIN attributes a ON a.id = av.attribute_id
				  WHERE a.slug = :a AND av.slug = :v
				  LIMIT 1"
			);
			$stmt->execute( [ ':a' => $attr_slug, ':v' => $value_slug ] );
			$row = $stmt->fetch();
			if ( ! $row ) return null;
			$value_ids[] = (int) $row['id'];
		}

		$placeholders = implode( ',', array_fill( 0, count( $value_ids ), '?' ) );
		$stmt = $pdo->prepare(
			"SELECT pv.id
			   FROM product_variants pv
			   JOIN variant_attribute_values vav ON vav.variant_id = pv.id
			  WHERE pv.product_id = ?
			    AND pv.enabled = 1
			    AND vav.attribute_value_id IN ($placeholders)
			  GROUP BY pv.id
			 HAVING COUNT(DISTINCT vav.attribute_value_id) = ?
			  LIMIT 1"
		);
		$args = array_merge( [ $productId ], $value_ids, [ count( $value_ids ) ] );
		$stmt->execute( $args );
		$row = $stmt->fetch();
		return $row ? (int) $row['id'] : null;
	}

	/**
	 * Reverse: get the option labels for a variant (for cart line display).
	 *
	 * @return array<string,string>  e.g. [ 'Color' => 'Red', 'Size' => 'L' ]
	 */
	public function optionsForVariant( int $variantId ): array {
		$stmt = DB::pdo()->prepare(
			"SELECT a.name AS attr_name, av.value AS val
			   FROM variant_attribute_values vav
			   JOIN attribute_values av ON av.id = vav.attribute_value_id
			   JOIN attributes a ON a.id = av.attribute_id
			  WHERE vav.variant_id = :v
			  ORDER BY a.position ASC"
		);
		$stmt->execute( [ ':v' => $variantId ] );
		$out = [];
		foreach ( $stmt->fetchAll() as $r ) {
			$out[ (string) $r['attr_name'] ] = (string) $r['val'];
		}
		return $out;
	}
}
