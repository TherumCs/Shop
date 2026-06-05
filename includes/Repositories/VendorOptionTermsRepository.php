<?php
/**
 * Shop by Therum — VendorOptionTermsRepository.
 *
 * The per-vendor "this is what they call it" → "this is what we call it"
 * dictionary. Example rows for the printful vendor:
 *
 *   pod_provider=printful, option_type=color, source_term=Ocean,
 *     canonical_term=Blue, confidence=confirmed
 *
 *   pod_provider=printful, option_type=color, source_term=Athletic Heather,
 *     canonical_term=Grey, confidence=auto
 *
 * Auto entries are best-guesses surfaced to the admin for confirmation;
 * confirmed entries drive merge suggestions + feed normalization without
 * further prompting.
 */

namespace Shop\Repositories;

use Shop\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

final class VendorOptionTermsRepository {

	/**
	 * Look up the canonical term for a vendor's source term.
	 * Returns null if no mapping exists.
	 */
	public function lookup( string $provider, string $optionType, string $sourceTerm ): ?string {
		$stmt = DB::pdo()->prepare(
			"SELECT canonical_term
			   FROM vendor_option_terms
			  WHERE pod_provider = :p AND option_type = :t AND source_term = :s
			    COLLATE NOCASE
			  LIMIT 1"
		);
		$stmt->execute( [
			':p' => $provider,
			':t' => $optionType,
			':s' => $sourceTerm,
		] );
		$row = $stmt->fetch();
		return $row ? (string) $row['canonical_term'] : null;
	}

	/**
	 * Reverse lookup — given a canonical term, return all source terms
	 * across this vendor that map to it. Useful for picking which of a
	 * vendor's variants to source for a master 'Blue'.
	 *
	 * @return string[]
	 */
	public function sourcesFor( string $provider, string $optionType, string $canonicalTerm ): array {
		$stmt = DB::pdo()->prepare(
			"SELECT source_term
			   FROM vendor_option_terms
			  WHERE pod_provider = :p AND option_type = :t AND canonical_term = :c
			    COLLATE NOCASE"
		);
		$stmt->execute( [
			':p' => $provider,
			':t' => $optionType,
			':c' => $canonicalTerm,
		] );
		return array_map( fn( array $r ): string => (string) $r['source_term'], $stmt->fetchAll() );
	}

	/**
	 * Upsert. New entries default to 'confirmed' (admin called this);
	 * pass 'auto' to record a system-inferred guess that needs review.
	 */
	public function upsert(
		string $provider,
		string $optionType,
		string $sourceTerm,
		string $canonicalTerm,
		string $confidence = 'confirmed',
	): void {
		DB::pdo()->prepare(
			"INSERT INTO vendor_option_terms (pod_provider, option_type, source_term, canonical_term, confidence)
			 VALUES (:p, :t, :s, :c, :conf)
			 ON CONFLICT(pod_provider, option_type, source_term)
			   DO UPDATE SET
			     canonical_term = excluded.canonical_term,
			     confidence     = excluded.confidence,
			     updated_at     = unixepoch()"
		)->execute( [
			':p'    => $provider,
			':t'    => $optionType,
			':s'    => $sourceTerm,
			':c'    => $canonicalTerm,
			':conf' => $confidence,
		] );
	}

	public function delete( string $provider, string $optionType, string $sourceTerm ): void {
		DB::pdo()->prepare(
			"DELETE FROM vendor_option_terms
			  WHERE pod_provider = :p AND option_type = :t AND source_term = :s
			    COLLATE NOCASE"
		)->execute( [
			':p' => $provider,
			':t' => $optionType,
			':s' => $sourceTerm,
		] );
	}

	/**
	 * List entries for one vendor, optionally filtered by option_type +
	 * confidence. Used by the admin dictionary page.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function list( ?string $provider = null, ?string $optionType = null, ?string $confidence = null ): array {
		$where = [];
		$bind  = [];
		if ( $provider )   { $where[] = 'pod_provider = :p'; $bind[':p'] = $provider; }
		if ( $optionType ) { $where[] = 'option_type = :t';  $bind[':t'] = $optionType; }
		if ( $confidence ) { $where[] = 'confidence = :c';   $bind[':c'] = $confidence; }
		$sql = 'SELECT id, pod_provider, option_type, source_term, canonical_term, confidence, created_at, updated_at FROM vendor_option_terms';
		if ( $where ) $sql .= ' WHERE ' . implode( ' AND ', $where );
		$sql .= ' ORDER BY pod_provider ASC, option_type ASC, source_term ASC';
		$stmt = DB::pdo()->prepare( $sql );
		$stmt->execute( $bind );
		return $stmt->fetchAll();
	}
}
