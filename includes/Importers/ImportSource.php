<?php
/**
 * Shop by Therum — ImportSource.
 *
 * Wraps the input to an importer. One of: a file path, a URL, or raw
 * text. The optional mimeType + filename hints help the registry
 * dispatch to the right importer.
 */

namespace Shop\Importers;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImportSource {

	public function __construct(
		public readonly ?string $filePath = null,
		public readonly ?string $url      = null,
		public readonly ?string $text     = null,
		public readonly ?string $mimeType = null,
		public readonly ?string $filename = null,
		/** @var array<string,mixed> */
		public readonly array $options    = [],
	) {}

	public static function file( string $path, ?string $mime = null ): self {
		return new self(
			filePath: $path,
			mimeType: $mime,
			filename: basename( $path ),
		);
	}

	public static function url( string $url ): self {
		return new self( url: $url );
	}

	public static function text( string $text, string $mime = 'text/plain' ): self {
		return new self( text: $text, mimeType: $mime );
	}

	public function extension(): string {
		if ( $this->filename !== null ) {
			return strtolower( pathinfo( $this->filename, PATHINFO_EXTENSION ) );
		}
		if ( $this->url !== null ) {
			$path = parse_url( $this->url, PHP_URL_PATH );
			if ( is_string( $path ) ) {
				return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			}
		}
		return '';
	}

	public function read(): string {
		if ( $this->text !== null )     return $this->text;
		if ( $this->filePath !== null ) return (string) @file_get_contents( $this->filePath );
		if ( $this->url !== null ) {
			$resp = wp_remote_get( $this->url, [ 'timeout' => 30 ] );
			return is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
		}
		return '';
	}
}
