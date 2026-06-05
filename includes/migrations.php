<?php
/**
 * Shop by Therum — migration runner.
 *
 * Replaces the old dbDelta-based shop_run_migrations(). Reads the current
 * schema version from the SQLite file's own schema_version table; if behind
 * Schema::VERSION, runs the DDL and any per-version upgrade callbacks.
 *
 * Forward-only: we never auto-downgrade.
 *
 * Invocation:
 *   - Plugin activation (register_activation_hook)
 *   - admin_init catch-up if the user updated without re-activating
 *   - Test bootstrap
 */

namespace Shop;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Migrations {

	/**
	 * Run schema setup. Safe to call repeatedly — every DDL statement is
	 * idempotent (CREATE TABLE IF NOT EXISTS / CREATE INDEX IF NOT EXISTS).
	 * Returns the version that's now applied.
	 */
	public static function run(): int {
		$pdo = DB::pdo();

		DB::tx( function ( \PDO $pdo ) {
			foreach ( Schema::statements() as $sql ) {
				$pdo->exec( $sql );
			}
		} );

		$current = self::currentVersion();

		if ( $current < Schema::VERSION ) {
			for ( $v = $current + 1; $v <= Schema::VERSION; $v++ ) {
				self::applyVersion( $v );
			}

			$stmt = $pdo->prepare( "INSERT INTO schema_version (version, applied_at) VALUES (:v, unixepoch())" );
			$stmt->execute( [ ':v' => Schema::VERSION ] );
		}

		return Schema::VERSION;
	}

	/**
	 * Highest schema version recorded in the SQLite file, or 0 if fresh.
	 */
	public static function currentVersion(): int {
		$pdo = DB::pdo();
		$row = $pdo->query( "SELECT MAX(version) AS v FROM schema_version" )->fetch();
		return (int) ( $row['v'] ?? 0 );
	}

	/**
	 * Per-version data migrations. Structural DDL lives in Schema::statements()
	 * and runs above — this is for moves that can't be expressed as idempotent
	 * DDL: backfilling a column, reshaping JSON, splitting a table.
	 */
	private static function applyVersion( int $version ): void {
		match ( $version ) {
			2 => self::v2_seedDefaultTemplates(),
			3 => self::v3_seedDefaultChrome(),
			4 => null, // v4 — customers table is pure DDL, no data move
			default => null,
		};
	}

	/**
	 * v2 — Pages table arrived. Seed default starter templates so
	 * first-time admins have something to edit instead of an empty
	 * builder. Idempotent — TemplateSeeder skips slots that already
	 * have a template assigned.
	 */
	private static function v2_seedDefaultTemplates(): void {
		$seeder = new Services\TemplateSeeder( new Repositories\PageRepository() );
		$seeder->seedAll();
	}

	/**
	 * v3 — ChromeResolver arrived. Seed a default header + footer so
	 * Pure pages render with chrome out of the box. Idempotent —
	 * `seedChrome()` skips kinds that already have any page.
	 */
	private static function v3_seedDefaultChrome(): void {
		$seeder = new Services\TemplateSeeder( new Repositories\PageRepository() );
		$seeder->seedChrome();
	}
}

/**
 * Back-compat shim — the activation hook from 0.1.0 calls the free function.
 * Keep it pointing at the new runner so legacy installs don't break.
 */
function shop_run_migrations(): void {
	\Shop\Migrations::run();
}
