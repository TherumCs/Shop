<?php
/**
 * Shop by Therum — Exporter contract.
 *
 * One impl per output format. Two consumer paths share this surface:
 *
 *   1. Admin export wizard / WP-CLI: pick format → ExportQuery → stream
 *      ExportResult to the browser or stdout.
 *   2. Feed endpoints: /shop/v1/feeds/{provider}.{ext} — Google
 *      Shopping, Meta Catalog, TikTok hit these on a schedule. Same
 *      Exporter, no auth, served as a public file.
 *
 * Implementations declare their MIME type and file extension so the
 * caller doesn't need to know format-specific details.
 *
 * Vendor option normalization: feeds use VendorDictionaryService to
 * translate "Printful Ocean" → "Blue" before emit. Google rejects
 * non-standard color names; the dictionary makes feed quality high
 * by default.
 */

namespace Shop\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

interface Exporter {

	/** Short slug: 'csv', 'markdown', 'json-ld', 'google-shopping', etc. */
	public function id(): string;

	/** Display label for the admin export picker. */
	public function displayName(): string;

	/** MIME type the output should be served with. */
	public function mimeType(): string;

	/** File extension (no leading dot). */
	public function extension(): string;

	/**
	 * Produce the export. Caller streams the returned ExportResult to
	 * the client.
	 */
	public function export( ExportQuery $query ): ExportResult;
}
