<?php
/**
 * Shop by Therum — service container.
 *
 * Small, deliberate DI container. Two kinds of bindings:
 *
 *   set(id, factory)        — every get() runs factory(), returns a fresh instance.
 *                             Use for stateful or per-request services.
 *
 *   singleton(id, factory)  — first get() runs factory(); subsequent get()s
 *                             return the cached instance. Use for stateless
 *                             services (the common case).
 *
 * Identifiers are class-or-interface names — e.g. Shop\Services\CartService::class.
 * Bindings can return any object; the type is up to the caller. Resolution is
 * O(1); no reflection magic, no auto-wiring. Explicit beats clever.
 *
 * Why not Pimple / Symfony Container / Laravel Container?
 *   - We don't need autowiring or compiled containers.
 *   - One file, ~50 lines, zero dependencies. Stays out of the way.
 *   - Reading the bootstrap tells you exactly what's wired.
 */

namespace Shop;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Container {

	private static ?self $instance = null;

	/** @var array<string, callable> */
	private array $factories = [];

	/** @var array<string, bool> */
	private array $singletons = [];

	/** @var array<string, object> */
	private array $instances = [];

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Bind a fresh-each-time factory. */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->singletons[ $id ], $this->instances[ $id ] );
	}

	/** Bind a single-instance factory. */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ]  = $factory;
		$this->singletons[ $id ] = true;
		unset( $this->instances[ $id ] );
	}

	/** Resolve a binding. Throws if id is unknown — explicit is better than null. */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( "Container: no binding for {$id}" );
		}

		$obj = ( $this->factories[ $id ] )( $this );

		if ( ! empty( $this->singletons[ $id ] ) ) {
			$this->instances[ $id ] = $obj;
		}

		return $obj;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/** Test helper — wipe a single binding so tests can rebind. */
	public function forget( string $id ): void {
		unset( $this->factories[ $id ], $this->singletons[ $id ], $this->instances[ $id ] );
	}
}

/**
 * Convenience global. `shop()` returns the container; `shop(Foo::class)`
 * resolves Foo. Mirrors Laravel's `app()` for ergonomics, without inheriting
 * anything else.
 */
function shop( ?string $id = null ): object {
	$c = Container::instance();
	return $id === null ? $c : $c->get( $id );
}
