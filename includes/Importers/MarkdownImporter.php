<?php
/**
 * Shop by Therum — Markdown / plain text importer.
 *
 * Heading hierarchy maps to products. A # or ## heading starts a new
 * product. Subsequent paragraphs become the description until the next
 * heading. Prices are detected via currency regex anywhere in the
 * product's text block (`$29.99`, `$1,299`, `USD 29.99`, `29.99 EUR`).
 *
 * Image lines are markdown images: `![alt](url)`. The first image in
 * a product's block becomes the primary image.
 *
 * Optional inline metadata as a key:value list under the heading:
 *
 *   # Therum Studio Tee
 *   SKU: TS-2024-RED-LG
 *   Stock: 25
 *
 *   A premium cotton tee in classic Therum red...
 *
 *   ![](https://cdn.example.com/tee-red.jpg)
 *
 *   $29.99
 *
 * No AI needed. Pure heuristic. Fast.
 */

namespace Shop\Importers;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class MarkdownImporter implements Importer {

	public function id(): string          { return 'markdown'; }
	public function displayName(): string { return 'Markdown / plain text'; }

	public function accepts( ImportSource $source ): bool {
		$ext = $source->extension();
		if ( in_array( $ext, [ 'md', 'markdown', 'mdx', 'txt' ], true ) ) return true;
		return in_array( $source->mimeType, [
			'text/markdown', 'text/plain', 'text/x-markdown',
		], true );
	}

	public function preview( ImportSource $source ): ImportResult {
		$raw = $source->read();
		if ( $raw === '' ) {
			return new ImportResult( [], 'Empty file', $this->id() );
		}

		// Split on level-1 and level-2 headings (# Title or ## Title).
		// Anything before the first heading is preamble, discarded.
		$blocks = preg_split( '/^(#{1,2})\s+(.+?)\s*$/m', $raw, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( count( $blocks ) < 4 ) {
			return new ImportResult( [], 'No product headings found. Use # or ## headings for product titles.', $this->id() );
		}

		// $blocks is [preamble, level, title, body, level, title, body, ...].
		array_shift( $blocks );
		$products = [];
		$line     = 1 + substr_count( $blocks[0] ?? '', "\n" );

		for ( $i = 0; $i < count( $blocks ); $i += 3 ) {
			$title = trim( $blocks[ $i + 1 ] ?? '' );
			$body  = (string) ( $blocks[ $i + 2 ] ?? '' );
			if ( $title === '' ) continue;
			$products[] = $this->parseProduct( $title, $body, $line );
			$line += 1 + substr_count( $body, "\n" );
		}

		$total = count( $products );
		$summary = sprintf(
			'Parsed %d product%s from %s.',
			$total,
			$total === 1 ? '' : 's',
			$source->filename ?? 'markdown'
		);

		return new ImportResult( $products, $summary, $this->id() );
	}

	private function parseProduct( string $title, string $body, int $lineStart ): PreviewProduct {
		$lines  = explode( "\n", $body );
		$meta   = [];
		$desc   = [];
		$images = [];
		$prices = [];
		$inMeta = true;
		$blank  = false;

		foreach ( $lines as $line ) {
			$trim = trim( $line );

			// Detect markdown image
			if ( preg_match( '/!\[[^\]]*\]\(([^)]+)\)/', $trim, $img ) ) {
				$images[] = $img[1];
				continue;
			}

			// Detect price anywhere in the line
			if ( preg_match( '/(?:USD\s+)?\$?\s*([0-9]{1,3}(?:[,.][0-9]{3})*(?:[.,][0-9]{2})?)\s*(?:USD|EUR|GBP)?/i', $trim, $m ) ) {
				$prices[] = $m[1];
			}

			// Detect key:value meta when still in the meta block (top of section)
			if ( $inMeta && preg_match( '/^([A-Za-z][A-Za-z ]+):\s*(.+)$/', $trim, $kv ) ) {
				$meta[ strtolower( trim( $kv[1] ) ) ] = trim( $kv[2] );
				continue;
			}

			if ( $trim === '' ) {
				$blank = true;
				continue;
			}

			// First non-meta, non-blank line ends the meta block.
			$inMeta = false;
			$desc[] = $line;
		}

		// Description = collected lines, trimmed
		$description = trim( implode( "\n", $desc ) );
		if ( $description === '' ) $description = null;

		$price = null;
		if ( $prices ) {
			$cleaned = str_replace( ',', '', $prices[0] );
			try { $price = Money::fromMajor( $cleaned, 'USD' ); } catch ( \Throwable ) {}
		}

		$issues = [];
		if ( $price === null )  $issues[] = 'No price detected.';
		if ( ! $images )        $issues[] = 'No image URL — add one after import.';

		$confidence = 0.5
			+ ( $price !== null  ? 0.2 : 0 )
			+ ( ! empty( $images ) ? 0.15 : 0 )
			+ ( ! empty( $meta['sku'] ) ? 0.15 : 0 );

		return new PreviewProduct(
			title:           $title,
			description:     $description,
			sku:             $meta['sku'] ?? null,
			price:           $price,
			compareAtPrice:  null,
			stockQty:        isset( $meta['stock'] ) && is_numeric( $meta['stock'] ) ? (int) $meta['stock'] : null,
			imageUrls:       $images,
			attributes:      [],
			variants:        [],
			confidence:      min( 1.0, $confidence ),
			sourceRef:       "line {$lineStart}",
			issues:          $issues,
		);
	}
}
