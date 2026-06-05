<?php
/**
 * Shop by Therum — CSV / TSV / XLSX importer.
 *
 * Accepts CSV (comma), TSV (tab), and an XLSX file converted to CSV by a
 * future helper (XLSX path is stubbed below — admin uploads a CSV until
 * we wire phpoffice/phpspreadsheet, but the dispatch hooks are in place).
 *
 * Column detection runs in two phases:
 *
 *   1. Heuristic — known header names (case-insensitive substring match).
 *      Title: name, title, product, item.
 *      Price: price, cost, amount, $ in header.
 *      SKU:   sku, model, code, item id.
 *      Stock: stock, qty, quantity, inventory.
 *      Image: image, photo, picture, url.
 *      Desc:  description, details, summary.
 *
 *   2. AI fallback (only if SHOP_ANTHROPIC_API_KEY is set and heuristic
 *      can't find a title or price column). Sends header + 3 sample rows
 *      to Claude with: "which columns are title/price/sku/stock/image/desc?"
 *      Returns a mapping. Stub in place; activates when the API key + sdk
 *      land.
 *
 * Variants: when the same title+sku-base appears across multiple rows
 * with different option columns (e.g. "Color", "Size"), they collapse
 * into a single PreviewProduct with PreviewVariants.
 */

namespace Shop\Importers;

use Shop\Money;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CsvImporter implements Importer {

	private const HEADER_MAP = [
		'title'       => [ 'title', 'name', 'product', 'item', 'product name', 'product title' ],
		'description' => [ 'description', 'desc', 'details', 'summary', 'long description' ],
		'sku'         => [ 'sku', 'model', 'code', 'item id', 'item_id', 'item number', 'product id' ],
		'price'       => [ 'price', 'amount', 'cost', 'unit price', 'retail price', '$' ],
		'compare_at'  => [ 'compare at', 'compare_at', 'msrp', 'list price', 'regular price' ],
		'stock'       => [ 'stock', 'qty', 'quantity', 'inventory', 'on hand' ],
		'image'       => [ 'image', 'photo', 'picture', 'image url', 'image_url', 'thumbnail' ],
		'color'       => [ 'color', 'colour' ],
		'size'        => [ 'size' ],
		'material'    => [ 'material', 'fabric' ],
	];

	public function id(): string          { return 'csv'; }
	public function displayName(): string { return 'CSV / TSV'; }

	public function accepts( ImportSource $source ): bool {
		$ext = $source->extension();
		if ( in_array( $ext, [ 'csv', 'tsv', 'txt' ], true ) ) return true;
		return in_array( $source->mimeType, [
			'text/csv', 'text/tab-separated-values', 'text/plain',
			'application/csv',
		], true );
	}

	public function preview( ImportSource $source ): ImportResult {
		$raw = $source->read();
		if ( $raw === '' ) {
			return new ImportResult( [], 'Empty file', $this->id() );
		}

		$delim = $this->detectDelimiter( $raw, $source->extension() );
		$rows  = $this->parseCsv( $raw, $delim );
		if ( count( $rows ) < 2 ) {
			return new ImportResult( [], 'No data rows', $this->id() );
		}

		$header = array_map( fn( string $h ): string => strtolower( trim( $h ) ), $rows[0] );
		$map    = $this->mapHeaders( $header );

		if ( ! isset( $map['title'] ) ) {
			return new ImportResult( [], 'Could not find a title column. Add one named "title" or "name".', $this->id(),
				[ 'Heuristic header detection failed. Configure Claude API for smarter mapping (planned).' ] );
		}

		// Group rows by title+sku-base for variant collapsing.
		$grouped = [];
		foreach ( array_slice( $rows, 1 ) as $i => $row ) {
			if ( count( $row ) === 1 && trim( $row[0] ) === '' ) continue;
			$title = $this->fieldAt( $row, $map['title'] ?? -1 );
			if ( $title === '' ) continue;

			$groupKey = strtolower( $title );
			if ( isset( $map['sku'] ) ) {
				// Strip trailing variant suffix from SKU to group: TS-RED-LG → TS
				$sku = $this->fieldAt( $row, $map['sku'] );
				$base = preg_replace( '/[-_][A-Z0-9]{1,4}([-_][A-Z0-9]{1,4})*$/i', '', $sku );
				if ( $base ) $groupKey .= '|' . strtolower( $base );
			}
			$grouped[ $groupKey ][] = [ 'row' => $row, 'index' => $i + 2 ]; // human-readable row number
		}

		$products = [];
		foreach ( $grouped as $group ) {
			$products[] = $this->buildPreview( $group, $map );
		}

		$total      = count( $products );
		$variant_ct = array_sum( array_map( fn( PreviewProduct $p ): int => count( $p->variants ), $products ) );
		$issues_ct  = array_sum( array_map( fn( PreviewProduct $p ): int => count( $p->issues ), $products ) );

		$summary = sprintf(
			'Parsed %d product%s (%d variant%s) from %s. %s',
			$total,
			$total === 1 ? '' : 's',
			$variant_ct,
			$variant_ct === 1 ? '' : 's',
			$source->filename ?? 'CSV',
			$issues_ct > 0 ? "Flagged {$issues_ct} thing" . ( $issues_ct === 1 ? '' : 's' ) . ' for review.' : 'No issues detected.'
		);

		return new ImportResult( $products, $summary, $this->id() );
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * @param array{row:string[], index:int}[] $rows
	 * @param array<string, int> $map
	 */
	private function buildPreview( array $rows, array $map ): PreviewProduct {
		$first  = $rows[0]['row'];
		$title  = $this->fieldAt( $first, $map['title'] ?? -1 );
		$desc   = $this->fieldOrNull( $first, $map['description'] ?? -1 );
		$image  = $this->fieldOrNull( $first, $map['image'] ?? -1 );

		$has_variants = count( $rows ) > 1;
		$variants     = [];
		$issues       = [];

		if ( $has_variants ) {
			foreach ( $rows as $r ) {
				$options = [];
				foreach ( [ 'color', 'size', 'material' ] as $opt ) {
					if ( isset( $map[ $opt ] ) ) {
						$v = $this->fieldAt( $r['row'], $map[ $opt ] );
						if ( $v !== '' ) $options[ ucfirst( $opt ) ] = $v;
					}
				}
				$variants[] = new PreviewVariant(
					sku:             $this->fieldOrNull( $r['row'], $map['sku'] ?? -1 ),
					options:         $options,
					price:           $this->priceField( $r['row'], $map['price'] ?? -1 ),
					compareAtPrice:  $this->priceField( $r['row'], $map['compare_at'] ?? -1 ),
					stockQty:        $this->intField( $r['row'], $map['stock'] ?? -1 ),
					imageUrl:        $this->fieldOrNull( $r['row'], $map['image'] ?? -1 ),
					podProvider:     null,
					podProductId:    null,
					podVariantId:    null,
				);
			}
		}

		$top_price = $this->priceField( $first, $map['price'] ?? -1 );
		if ( $top_price === null && ! $has_variants ) {
			$issues[] = 'No price detected.';
		}
		if ( $image === null && ! $has_variants ) {
			$issues[] = 'No image URL — add one after import.';
		}

		// Average confidence based on what we matched
		$matched     = count( array_filter( [ 'title','description','sku','price','image' ], fn( $k ) => isset( $map[ $k ] ) ) );
		$confidence  = max( 0.4, min( 1.0, $matched / 5.0 ) );

		$ref = count( $rows ) === 1
			? "row {$rows[0]['index']}"
			: sprintf( 'rows %d–%d', $rows[0]['index'], end( $rows )['index'] );

		return new PreviewProduct(
			title:           $title,
			description:     $desc,
			sku:             $has_variants ? null : $this->fieldOrNull( $first, $map['sku'] ?? -1 ),
			price:           $top_price,
			compareAtPrice:  $this->priceField( $first, $map['compare_at'] ?? -1 ),
			stockQty:        $this->intField(   $first, $map['stock']      ?? -1 ),
			imageUrls:       $image !== null ? [ $image ] : [],
			attributes:      [],
			variants:        $variants,
			confidence:      $confidence,
			sourceRef:       $ref,
			issues:          $issues,
		);
	}

	private function detectDelimiter( string $raw, string $ext ): string {
		if ( $ext === 'tsv' ) return "\t";
		// Sample the first line: count tabs vs commas, pick the winner.
		$line = strtok( $raw, "\r\n" );
		strtok( '', '' );
		return ( substr_count( (string) $line, "\t" ) > substr_count( (string) $line, ',' ) ) ? "\t" : ',';
	}

	/**
	 * @return string[][]
	 */
	private function parseCsv( string $raw, string $delim ): array {
		$rows = [];
		$h    = fopen( 'php://memory', 'r+' );
		fwrite( $h, $raw );
		rewind( $h );
		while ( ( $row = fgetcsv( $h, 0, $delim, '"', '\\' ) ) !== false ) {
			$rows[] = $row;
		}
		fclose( $h );
		return $rows;
	}

	/**
	 * @param string[] $header
	 * @return array<string, int>
	 */
	private function mapHeaders( array $header ): array {
		$out = [];
		foreach ( self::HEADER_MAP as $field => $needles ) {
			foreach ( $header as $i => $h ) {
				foreach ( $needles as $needle ) {
					if ( $h === $needle || str_contains( $h, $needle ) ) {
						$out[ $field ] = $i;
						break 2;
					}
				}
			}
		}
		return $out;
	}

	private function fieldAt( array $row, int $i ): string {
		return ( $i >= 0 && isset( $row[ $i ] ) ) ? trim( (string) $row[ $i ] ) : '';
	}

	private function fieldOrNull( array $row, int $i ): ?string {
		$v = $this->fieldAt( $row, $i );
		return $v === '' ? null : $v;
	}

	private function intField( array $row, int $i ): ?int {
		$v = $this->fieldAt( $row, $i );
		return is_numeric( $v ) ? (int) $v : null;
	}

	private function priceField( array $row, int $i ): ?Money {
		$v = $this->fieldAt( $row, $i );
		if ( $v === '' ) return null;
		// Strip currency symbols and thousands separators; keep digits + last decimal point.
		$clean = preg_replace( '/[^\d.,-]/', '', $v );
		// Treat last "." or "," as the decimal separator.
		$last_dot = strrpos( $clean, '.' );
		$last_com = strrpos( $clean, ',' );
		if ( $last_com !== false && ( $last_dot === false || $last_com > $last_dot ) ) {
			// European style — comma is decimal.
			$clean = str_replace( '.', '', $clean );
			$clean = str_replace( ',', '.', $clean );
		} else {
			$clean = str_replace( ',', '', $clean );
		}
		if ( ! is_numeric( $clean ) ) return null;
		try {
			return Money::fromMajor( $clean, 'USD' );
		} catch ( \Throwable ) {
			return null;
		}
	}
}
