<?php
/**
 * Shop by Therum — ImportResult.
 *
 * Output of Importer::preview(). Carries the proposed products plus a
 * short importer-level summary the admin sees above the review grid:
 *
 *   "Parsed 47 products from products.csv. 3 rows had no price; 1 row
 *    had no title. Best guesses are flagged."
 */

namespace Shop\Importers;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImportResult {

	public function __construct(
		/** @var PreviewProduct[] */
		public readonly array $products,
		public readonly string $summary,
		public readonly string $importerId,
		/** @var string[] */
		public readonly array $warnings = [],
	) {}

	/** @return array<string,mixed> */
	public function toJson(): array {
		return [
			'importer_id' => $this->importerId,
			'summary'     => $this->summary,
			'warnings'    => $this->warnings,
			'count'       => count( $this->products ),
			'products'    => array_map(
				fn( PreviewProduct $p ): array => $p->toJson(),
				$this->products
			),
		];
	}
}
