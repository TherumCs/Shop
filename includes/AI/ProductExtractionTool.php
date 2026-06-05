<?php
/**
 * Shop by Therum — Claude tool: extract_products.
 *
 * One canonical JSON schema for "given this image/page, list the products
 * you see." Used by PdfImporter (per page), ImageImporter (single image),
 * and FigmaImporter (per rendered frame).
 *
 * The model is forced to call this tool (tool_choice = required) so the
 * output is always structured.
 */

namespace Shop\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ProductExtractionTool {

	public const NAME = 'extract_products';

	/**
	 * @return array<string,mixed>  the tool definition for the API
	 */
	public static function definition(): array {
		return [
			'name' => self::NAME,
			'description' =>
				'Extract every distinct product visible in the provided image or page. '
				. 'A product is a sellable item — typically a title, price, and (optional) description. '
				. 'Return an empty array if no products are visible (e.g. a cover page or table of contents). '
				. 'Be precise: do not invent prices, SKUs, or details that are not legible. '
				. 'Use null for fields you cannot see.',
			'input_schema' => [
				'type'     => 'object',
				'required' => [ 'products' ],
				'properties' => [
					'products' => [
						'type'  => 'array',
						'items' => [
							'type'     => 'object',
							'required' => [ 'title' ],
							'properties' => [
								'title' => [
									'type'        => 'string',
									'description' => 'The product name as printed.',
								],
								'description' => [
									'type'        => [ 'string', 'null' ],
									'description' => 'One-to-two sentence description if visible; null if absent.',
								],
								'sku' => [
									'type'        => [ 'string', 'null' ],
									'description' => 'Product code / SKU / item number if visible.',
								],
								'price_text' => [
									'type'        => [ 'string', 'null' ],
									'description' => 'Raw price string as printed (e.g. "$29.99", "USD 30"). Null if no price.',
								],
								'price_cents' => [
									'type'        => [ 'integer', 'null' ],
									'description' => 'Normalized price in cents (e.g. 2999 for $29.99). Use only if certain.',
								],
								'color' => [
									'type'        => [ 'string', 'null' ],
									'description' => 'Color attribute if visible / inferable from the image.',
								],
								'size' => [
									'type'        => [ 'string', 'null' ],
									'description' => 'Size attribute if visible.',
								],
								'material' => [
									'type'        => [ 'string', 'null' ],
									'description' => 'Material composition if visible.',
								],
								'image_bbox' => [
									'type'        => [ 'array', 'null' ],
									'description' =>
										'Bounding box of the product image as [x, y, width, height] '
										. 'in fractions of the page (0.0 to 1.0). Used to crop. '
										. 'Null if the page does not have a discrete product image.',
									'items' => [ 'type' => 'number' ],
								],
								'confidence' => [
									'type'        => 'number',
									'description' => '0.0 to 1.0. How confident you are this is a real, fully-extracted product.',
								],
							],
						],
					],
				],
			],
		];
	}
}
