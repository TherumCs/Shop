<?php
/**
 * Shop by Therum — PreviewProduct.
 *
 * A draft Product proposed by an importer. Carries the same fields the
 * real Product DTO does, plus detection metadata:
 *
 *   confidence — 0.0–1.0 — how sure the importer is. Anything < 0.5
 *                gets visually flagged in the review grid; admin can
 *                still confirm.
 *
 *   sourceRef  — a free-form pointer back to where in the source this
 *                product came from. PDF: "page 3, top-left". CSV:
 *                "row 12". URL: the page URL. Admin sees this so they
 *                can sanity-check before confirming.
 *
 *   issues     — strings describing anything that needs fixing. "No
 *                price detected — falling back to $0.00." "Image
 *                couldn't be extracted." Surfaced as warnings on the
 *                review card.
 */

namespace Shop\Importers;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PreviewProduct {

	public function __construct(
		public readonly string $title,
		public readonly ?string $description,
		public readonly ?string $sku,
		public readonly ?Money $price,
		public readonly ?Money $compareAtPrice,
		public readonly ?int $stockQty,
		/** @var string[]  Absolute URLs (importer doesn't sideload yet) */
		public readonly array $imageUrls,
		/** @var array<string,string>  e.g. ['Color' => 'Red', 'Size' => 'L'] */
		public readonly array $attributes,
		/** @var PreviewVariant[] */
		public readonly array $variants,
		public readonly float $confidence,
		public readonly string $sourceRef,
		/** @var string[] */
		public readonly array $issues,
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function toJson(): array {
		return [
			'title'             => $this->title,
			'description'       => $this->description,
			'sku'               => $this->sku,
			'price'             => $this->price?->minor,
			'price_fmt'         => $this->price?->format(),
			'compare_at_price'  => $this->compareAtPrice?->minor,
			'stock_qty'         => $this->stockQty,
			'image_urls'        => $this->imageUrls,
			'attributes'        => $this->attributes,
			'variants'          => array_map( fn( PreviewVariant $v ): array => $v->toJson(), $this->variants ),
			'confidence'        => $this->confidence,
			'source_ref'        => $this->sourceRef,
			'issues'            => $this->issues,
		];
	}
}
