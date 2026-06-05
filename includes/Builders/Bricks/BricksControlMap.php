<?php
/**
 * Shop by Therum — Bricks control mapper.
 *
 * Translates a Shop control schema row (the canonical format used by
 * the Pure editor + all adapters) into Bricks's native control format.
 *
 * Shop control types and their Bricks equivalents:
 *
 *   text          → text
 *   textarea      → textarea
 *   number        → number
 *   slider        → number with showInput + step
 *   toggle        → checkbox
 *   select        → select with optionsAsObject
 *   color         → color
 *   alignment     → align (Bricks native)
 *   image         → image
 *   productPicker → text (with description; full picker in v2 via a
 *                   custom Bricks control)
 *
 * Groups become Bricks tabs (Bricks UI is tabbed; we collapse groups
 * with the same name).
 */

namespace Shop\Builders\Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

final class BricksControlMap {

	/**
	 * @param array<int, array<string,mixed>> $shopControls
	 * @return array<string, array<string,mixed>>
	 */
	public static function translate( array $shopControls ): array {
		$out = [];

		foreach ( $shopControls as $row ) {
			$id      = (string) ( $row['id'] ?? '' );
			$type    = (string) ( $row['type'] ?? 'text' );
			$label   = (string) ( $row['label'] ?? '' );
			$default = $row['default'] ?? null;
			$group   = (string) ( $row['group'] ?? '' );

			$bricks = [
				'tab'     => 'content',
				'group'   => $group,
				'label'   => $label,
				'default' => $default,
			];

			switch ( $type ) {
				case 'text':
					$bricks['type'] = 'text';
					break;
				case 'textarea':
					$bricks['type'] = 'textarea';
					break;
				case 'number':
					$bricks['type'] = 'number';
					if ( isset( $row['min'] ) ) $bricks['min'] = $row['min'];
					if ( isset( $row['max'] ) ) $bricks['max'] = $row['max'];
					break;
				case 'toggle':
					$bricks['type'] = 'checkbox';
					break;
				case 'color':
					$bricks['type'] = 'color';
					$bricks['tab']  = 'style';
					break;
				case 'select':
					$bricks['type']            = 'select';
					$bricks['options']         = (array) ( $row['options'] ?? [] );
					$bricks['optionsAsObject'] = true;
					break;
				case 'alignment':
					$bricks['type'] = 'align';
					$bricks['tab']  = 'style';
					break;
				case 'image':
					$bricks['type'] = 'image';
					break;
				case 'productPicker':
					$bricks['type']        = 'text';
					$bricks['description'] = 'Enter product ID. Full picker in next release.';
					break;
				default:
					$bricks['type'] = 'text';
			}

			$out[ $id ] = $bricks;
		}

		return $out;
	}
}
