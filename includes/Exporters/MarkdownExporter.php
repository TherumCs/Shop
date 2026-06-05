<?php
/**
 * Shop by Therum — Markdown exporter.
 *
 * One `#` heading per product, key:value meta block (SKU, Stock,
 * Vendor), description paragraph, embedded image, and the price as a
 * standalone line. Round-trips through MarkdownImporter.
 *
 * Good for AI-readable catalogs (RAG, search), documentation, and
 * lightweight blog-post-style catalog dumps.
 */

namespace Shop\Exporters;

use Shop\Models\Product;

if ( ! defined( 'ABSPATH' ) ) exit;

final class MarkdownExporter implements Exporter {

	public function __construct( private readonly CatalogReader $reader ) {}

	public function id(): string          { return 'markdown'; }
	public function displayName(): string { return 'Markdown'; }
	public function mimeType(): string    { return 'text/markdown'; }
	public function extension(): string   { return 'md'; }

	public function export( ExportQuery $query ): ExportResult {
		$out = [
			'# Catalog',
			'',
			sprintf( '_Exported %s_', gmdate( 'Y-m-d H:i' ) ),
			'',
			'---',
			'',
		];

		$count = 0;
		foreach ( $this->reader->walk( $query ) as $product ) {
			$out = array_merge( $out, $this->productBlock( $product ) );
			$count++;
		}

		return ExportResult::string(
			body:     implode( "\n", $out ),
			filename: 'catalog-' . gmdate( 'Y-m-d' ) . '.md',
			mime:     $this->mimeType(),
			count:    $count,
		);
	}

	/** @return string[] */
	private function productBlock( Product $p ): array {
		$lines = [];
		$lines[] = '## ' . $p->title;
		$lines[] = '';

		$meta = [];
		if ( $p->sku )         $meta[] = 'SKU: '    . $p->sku;
		if ( $p->stockQty !== null ) $meta[] = 'Stock: '  . $p->stockQty;
		$meta[] = 'Status: ' . $p->status;

		foreach ( $meta as $line ) $lines[] = $line;
		$lines[] = '';

		if ( $p->shortDescription ) {
			$lines[] = trim( $p->shortDescription );
			$lines[] = '';
		} elseif ( $p->description ) {
			$lines[] = trim( $p->description );
			$lines[] = '';
		}

		if ( $p->primaryImageId !== null ) {
			$url = (string) wp_get_attachment_image_url( $p->primaryImageId, 'large' );
			if ( $url ) {
				$lines[] = sprintf( '![%s](%s)', esc_attr( $p->title ), $url );
				$lines[] = '';
			}
		}

		if ( $p->price !== null ) {
			$lines[] = $p->price->format();
			$lines[] = '';
		}

		$lines[] = '---';
		$lines[] = '';
		return $lines;
	}
}
