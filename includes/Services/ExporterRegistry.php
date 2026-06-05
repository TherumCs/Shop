<?php
/**
 * Shop by Therum — ExporterRegistry.
 *
 * Mirror of ImporterRegistry. Holds Exporter implementations addressable
 * by id. Filterable via `shop_register_exporters` action.
 */

namespace Shop\Services;

use Shop\Exporters\Exporter;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ExporterRegistry {

	/** @var Exporter[] */
	private array $exporters = [];

	public function register( Exporter $exporter ): void {
		$this->exporters[ $exporter->id() ] = $exporter;
	}

	public function get( string $id ): Exporter {
		if ( ! isset( $this->exporters[ $id ] ) ) {
			throw new \DomainException( "Unknown exporter: {$id}" );
		}
		return $this->exporters[ $id ];
	}

	public function has( string $id ): bool { return isset( $this->exporters[ $id ] ); }

	/** @return Exporter[] */
	public function all(): array { return array_values( $this->exporters ); }
}
