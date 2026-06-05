<?php
/**
 * Shop by Therum — PreviewVariant.
 *
 * Variant proposal attached to a PreviewProduct.
 */

namespace Shop\Importers;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PreviewVariant {

	public function __construct(
		public readonly ?string $sku,
		/** @var array<string,string>  e.g. ['Color' => 'Red', 'Size' => 'L'] */
		public readonly array $options,
		public readonly ?Money $price,
		public readonly ?Money $compareAtPrice,
		public readonly ?int $stockQty,
		public readonly ?string $imageUrl,
		public readonly ?string $podProvider,
		public readonly ?string $podProductId,
		public readonly ?string $podVariantId,
	) {}

	/** @return array<string,mixed> */
	public function toJson(): array {
		return [
			'sku'              => $this->sku,
			'options'          => $this->options,
			'price'            => $this->price?->minor,
			'price_fmt'        => $this->price?->format(),
			'compare_at_price' => $this->compareAtPrice?->minor,
			'stock_qty'        => $this->stockQty,
			'image_url'        => $this->imageUrl,
			'pod_provider'     => $this->podProvider,
			'pod_product_id'   => $this->podProductId,
			'pod_variant_id'   => $this->podVariantId,
		];
	}
}
