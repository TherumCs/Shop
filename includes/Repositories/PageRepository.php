<?php
/**
 * Shop by Therum — PageRepository.
 */

namespace Shop\Repositories;

use Shop\DB;
use Shop\Models\Page;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PageRepository {

	public function findById( int $id ): ?Page {
		$stmt = DB::pdo()->prepare( "SELECT * FROM pages WHERE id = :i" );
		$stmt->execute( [ ':i' => $id ] );
		$row = $stmt->fetch();
		return $row ? Page::fromRow( $row ) : null;
	}

	public function findBySlug( string $slug, string $kind = 'page' ): ?Page {
		$stmt = DB::pdo()->prepare( "SELECT * FROM pages WHERE slug = :s AND kind = :k" );
		$stmt->execute( [ ':s' => $slug, ':k' => $kind ] );
		$row = $stmt->fetch();
		return $row ? Page::fromRow( $row ) : null;
	}

	public function findByAssignment( string $assignment ): ?Page {
		$stmt = DB::pdo()->prepare(
			"SELECT * FROM pages
			  WHERE assigned_to = :a AND kind = 'template' AND status = 'published'
			  ORDER BY updated_at DESC LIMIT 1"
		);
		$stmt->execute( [ ':a' => $assignment ] );
		$row = $stmt->fetch();
		return $row ? Page::fromRow( $row ) : null;
	}

	/** @return Page[] */
	public function list( ?string $kind = null, ?string $status = null, int $limit = 100 ): array {
		$where = []; $bind = [];
		if ( $kind )   { $where[] = 'kind = :k';     $bind[':k'] = $kind; }
		if ( $status ) { $where[] = 'status = :s';   $bind[':s'] = $status; }
		$sql = 'SELECT * FROM pages';
		if ( $where ) $sql .= ' WHERE ' . implode( ' AND ', $where );
		$sql .= ' ORDER BY updated_at DESC LIMIT ' . (int) $limit;
		$stmt = DB::pdo()->prepare( $sql );
		$stmt->execute( $bind );
		return array_map( fn( array $r ): Page => Page::fromRow( $r ), $stmt->fetchAll() );
	}

	public function create(
		string $title,
		string $kind = 'page',
		?string $assignedTo = null,
	): Page {
		$pdo = DB::pdo();
		$uuid = wp_generate_uuid4();
		$slug = $this->uniqueSlug( sanitize_title( $title ) ?: 'page', $kind );
		$pdo->prepare(
			"INSERT INTO pages (uuid, slug, title, kind, assigned_to, status, tree, author_id)
			 VALUES (:u, :s, :t, :k, :a, 'draft', '[]', :au)"
		)->execute( [
			':u'  => $uuid,
			':s'  => $slug,
			':t'  => $title,
			':k'  => $kind,
			':a'  => $assignedTo,
			':au' => get_current_user_id() ?: null,
		] );
		$id = (int) $pdo->lastInsertId();
		return $this->findById( $id ) ?? throw new \RuntimeException( 'Page vanished post-insert' );
	}

	public function save( int $id, array $patch ): Page {
		$allowed = [ 'title', 'slug', 'status', 'assigned_to', 'tree', 'meta' ];
		$sets = []; $bind = [];
		foreach ( $patch as $k => $v ) {
			if ( ! in_array( $k, $allowed, true ) ) continue;
			if ( in_array( $k, [ 'tree', 'meta' ], true ) && ! is_string( $v ) ) {
				$v = wp_json_encode( $v );
			}
			$sets[] = "$k = :$k";
			$bind[":$k"] = $v;
		}
		if ( ! $sets ) return $this->findById( $id ) ?? throw new \RuntimeException( 'Page not found' );

		$sets[] = 'updated_at = unixepoch()';
		if ( isset( $patch['status'] ) && $patch['status'] === 'published' ) {
			$sets[] = 'published_at = COALESCE(published_at, unixepoch())';
		}
		$bind[':id'] = $id;
		DB::pdo()->prepare( 'UPDATE pages SET ' . implode( ', ', $sets ) . ' WHERE id = :id' )->execute( $bind );
		return $this->findById( $id ) ?? throw new \RuntimeException( 'Page vanished post-save' );
	}

	public function delete( int $id ): void {
		DB::pdo()->prepare( "DELETE FROM pages WHERE id = :i" )->execute( [ ':i' => $id ] );
	}

	private function uniqueSlug( string $base, string $kind ): string {
		$stmt = DB::pdo()->prepare( "SELECT 1 FROM pages WHERE slug = :s AND kind = :k" );
		$slug = $base; $n = 1;
		while ( true ) {
			$stmt->execute( [ ':s' => $slug, ':k' => $kind ] );
			if ( $stmt->fetch() === false ) return $slug;
			$slug = $base . '-' . ( ++$n );
		}
	}
}
