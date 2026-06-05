<?php
/**
 * Shop by Therum — ExportQuery.
 *
 * Filter spec for an export. All fields optional — empty query exports
 * the whole catalog.
 */

namespace Shop\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ExportQuery {

	public function __construct(
		public readonly ?string $status      = 'active',  // default: only active
		public readonly ?string $search      = null,
		public readonly ?string $podProvider = null,      // filter by vendor
		/** @var int[] */
		public readonly array $ids           = [],        // explicit id list (overrides other filters)
		public readonly int $limit           = 10000,
		public readonly int $offset          = 0,
		public readonly ?string $siteUrl     = null,      // for absolute product URLs in feeds
		/** @var array<string,mixed> */
		public readonly array $extra         = [],
	) {}
}
