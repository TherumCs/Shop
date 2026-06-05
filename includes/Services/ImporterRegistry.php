<?php
/**
 * Shop by Therum — ImporterRegistry.
 *
 * Holds the configured Importer implementations. Two dispatch modes:
 *
 *   pick( id )   — explicit selection by importer id
 *   route( src ) — auto-dispatch: ask each importer in priority order if
 *                  it accepts() the source, return the first match.
 *
 * Order matters for route() — more specific importers come first so the
 * generic fallbacks don't shadow them.
 */

namespace Shop\Services;

use Shop\Importers\Importer;
use Shop\Importers\ImportSource;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ImporterRegistry {

	/** @var Importer[] */
	private array $importers = [];

	public function register( Importer $importer ): void {
		$this->importers[ $importer->id() ] = $importer;
	}

	public function pick( string $id ): Importer {
		if ( ! isset( $this->importers[ $id ] ) ) {
			throw new \DomainException( "Unknown importer: {$id}" );
		}
		return $this->importers[ $id ];
	}

	public function route( ImportSource $source ): ?Importer {
		foreach ( $this->importers as $imp ) {
			if ( $imp->accepts( $source ) ) return $imp;
		}
		return null;
	}

	/** @return Importer[] */
	public function all(): array {
		return array_values( $this->importers );
	}
}
