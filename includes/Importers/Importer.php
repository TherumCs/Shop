<?php
/**
 * Shop by Therum — Importer contract.
 *
 * One implementation per source format (CSV, Markdown, PDF, Figma, URL,
 * single image). Each takes a payload and produces a list of
 * PreviewProduct DTOs — products with detection-time metadata
 * (confidence, source page/row reference) attached.
 *
 * The flow is intentionally "preview first": importers NEVER write
 * directly to the database. They produce previews, the admin reviews
 * and confirms, then ProductWriter inserts. This is the whole point —
 * Woo's importer is painful precisely because there's no honest review
 * stage between "you uploaded a CSV" and "you have new products you
 * didn't mean to publish."
 *
 * Implementations declare what they accept via `accepts()` so the
 * registry can dispatch automatically from a MIME type or filename.
 */

namespace Shop\Importers;

if ( ! defined( 'ABSPATH' ) ) exit;

interface Importer {

	/**
	 * Short slug — 'csv', 'pdf', 'markdown', 'url', 'figma', 'image'.
	 */
	public function id(): string;

	/**
	 * Display label for the admin UI.
	 */
	public function displayName(): string;

	/**
	 * Whether this importer can handle the given source. Implementations
	 * sniff MIME types, URL patterns, or file extensions.
	 *
	 * @param ImportSource $source
	 */
	public function accepts( ImportSource $source ): bool;

	/**
	 * Read the source and produce preview products. Throws on
	 * unrecoverable parse failure; returns an empty array if the source
	 * looks fine but contains no products.
	 *
	 * @return ImportResult
	 */
	public function preview( ImportSource $source ): ImportResult;
}
