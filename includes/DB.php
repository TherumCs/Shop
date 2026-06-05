<?php
/**
 * Shop by Therum — SQLite connection.
 *
 * The plugin owns its own SQLite file. WordPress's own database (MySQL or
 * otherwise) is untouched. The file lives in wp-content/uploads/therum-shop/
 * so it's inside the WP upload tree (gets backed up with the site, survives
 * plugin updates).
 *
 * Pragmas applied on every connection:
 *   - journal_mode = WAL    → readers don't block writers, big throughput win
 *   - synchronous  = NORMAL → safe under WAL, ~5× faster than FULL
 *   - foreign_keys = ON     → FKs are off by default in SQLite; we need them
 *   - busy_timeout = 5000ms → retry on lock instead of failing immediately
 *
 * Access is through Shop\DB::pdo() — singleton. Caller writes plain SQL with
 * named placeholders. No ORM, no query builder, no abstraction tax.
 */

namespace Shop;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DB {

	private static ?\PDO $pdo = null;

	/**
	 * Get the live PDO connection. Lazy-opens on first call.
	 */
	public static function pdo(): \PDO {
		if ( self::$pdo === null ) {
			self::$pdo = self::open();
		}
		return self::$pdo;
	}

	/**
	 * Absolute path to the SQLite file. Public so the uninstaller and any
	 * future backup tooling can reach it.
	 */
	public static function path(): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'therum-shop';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Lock the directory down — no web access. wp-content/uploads is
			// world-readable by default; the .htaccess + index.php guard the
			// SQLite file from being fetched over HTTP.
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php',  "<?php // Silence is golden.\n" );
		}
		return $dir . '/shop.sqlite';
	}

	/**
	 * Force-close the connection. Used by the uninstaller before deleting
	 * the file. Tests can also use this to reset state.
	 */
	public static function close(): void {
		self::$pdo = null;
	}

	private static function open(): \PDO {
		$path = self::path();

		$pdo = new \PDO( 'sqlite:' . $path, null, null, [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_EMULATE_PREPARES   => false,
		] );

		// Order matters — WAL must be set before heavy writes, FKs must be
		// set per-connection (not persistent in the file).
		$pdo->exec( 'PRAGMA journal_mode = WAL' );
		$pdo->exec( 'PRAGMA synchronous = NORMAL' );
		$pdo->exec( 'PRAGMA foreign_keys = ON' );
		$pdo->exec( 'PRAGMA busy_timeout = 5000' );
		// Slightly bigger page cache; ~8 MB of pages in memory.
		$pdo->exec( 'PRAGMA cache_size = -8000' );

		return $pdo;
	}

	/**
	 * Run a closure inside a transaction. Rolls back on exception, re-throws.
	 *
	 * @template T
	 * @param callable(\PDO): T $fn
	 * @return T
	 */
	public static function tx( callable $fn ) {
		$pdo = self::pdo();
		$pdo->beginTransaction();
		try {
			$result = $fn( $pdo );
			$pdo->commit();
			return $result;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}
	}
}
