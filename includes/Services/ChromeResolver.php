<?php
/**
 * Shop by Therum — ChromeResolver.
 *
 * Picks which header / footer to render around every Pure page. Rules:
 *
 *   1. If a `shop_active_header_id` / `shop_active_footer_id` option is
 *      set, use that page id (admin pinned a specific one).
 *   2. Otherwise, fall back to the most recently *published* header /
 *      footer by id.
 *   3. If nothing exists, return null and the renderer emits no chrome
 *      (lets the theme handle it). Pure mode without a header is still
 *      a valid configuration on day one — the seeder doesn't ship a
 *      default header / footer yet.
 *
 * Results are memoized per request so a page render doesn't hit the
 * DB twice (header during the prefix, footer during the suffix).
 */

namespace Shop\Services;

use Shop\Models\Page;
use Shop\Repositories\PageRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ChromeResolver {

	private ?Page $headerCache  = null;
	private bool  $headerLooked = false;
	private ?Page $footerCache  = null;
	private bool  $footerLooked = false;

	public function __construct( private readonly PageRepository $pages ) {}

	public function activeHeader(): ?Page {
		if ( $this->headerLooked ) return $this->headerCache;
		$this->headerLooked = true;
		return $this->headerCache = $this->resolveKind( Page::KIND_HEADER, 'shop_active_header_id' );
	}

	public function activeFooter(): ?Page {
		if ( $this->footerLooked ) return $this->footerCache;
		$this->footerLooked = true;
		return $this->footerCache = $this->resolveKind( Page::KIND_FOOTER, 'shop_active_footer_id' );
	}

	private function resolveKind( string $kind, string $option ): ?Page {
		$pinned = (int) get_option( $option, 0 );
		if ( $pinned > 0 ) {
			$page = $this->pages->findById( $pinned );
			if ( $page !== null && $page->kind === $kind ) return $page;
		}
		// Fall back to newest published of that kind.
		$list = $this->pages->list( $kind, 'published', 1 );
		return $list[0] ?? null;
	}
}
