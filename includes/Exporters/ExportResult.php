<?php
/**
 * Shop by Therum — ExportResult.
 *
 * What an exporter returns. For text formats (CSV, XML, JSON, MD) the
 * body is a string the caller streams directly. For binary formats
 * (PDF, image), the body is a file path on disk (typically a tmpfile).
 *
 * `count` is the number of products in the export, used for the admin
 * "exported N products" message.
 */

namespace Shop\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ExportResult {

	public function __construct(
		public readonly string $body,           // in-memory body OR path to tmpfile
		public readonly bool $isFile,           // true = body is a file path
		public readonly string $filename,       // suggested download name
		public readonly string $mimeType,
		public readonly int $count,
		/** @var string[] */
		public readonly array $warnings = [],
	) {}

	public static function string( string $body, string $filename, string $mime, int $count, array $warnings = [] ): self {
		return new self( $body, false, $filename, $mime, $count, $warnings );
	}

	public static function file( string $path, string $filename, string $mime, int $count, array $warnings = [] ): self {
		return new self( $path, true, $filename, $mime, $count, $warnings );
	}
}
