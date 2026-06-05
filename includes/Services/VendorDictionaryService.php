<?php
/**
 * Shop by Therum — VendorDictionaryService.
 *
 * Three operations:
 *
 *   translate( provider, type, sourceTerm )
 *     Returns the canonical term if known, else null. Read path.
 *
 *   confirm( provider, type, sourceTerm, canonicalTerm )
 *     Admin-confirmed mapping. Overrides any auto guess. Write path
 *     from the dictionary admin UI and the merge flow.
 *
 *   suggest( provider, type, sourceTerm, canonicalKnownTerms[] )
 *     The "learn over time" part. When the system encounters a new
 *     source term during merge or import:
 *       1. Try an existing confirmed mapping first.
 *       2. If none, fuzzy-match the sourceTerm against every known
 *          canonical term (Levenshtein + substring). If similarity ≥ 0.7,
 *          record an `auto` entry and return that guess.
 *       3. If nothing close enough, return null (admin will hand-map).
 *
 *     Confidence stays 'auto' until the admin opens the entry and
 *     accepts it (which calls confirm()). That accept is one click in
 *     the dictionary UI.
 *
 * `suggest()` plus the upsert means the dictionary fills itself in as
 * the admin merges products. By product 50 with a given vendor, hand-
 * mapping should be a rare exception.
 */

namespace Shop\Services;

use Shop\Repositories\VendorOptionTermsRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class VendorDictionaryService {

	private const AUTO_THRESHOLD = 0.7;

	public function __construct(
		private readonly VendorOptionTermsRepository $terms,
	) {}

	public function translate( string $provider, string $optionType, string $sourceTerm ): ?string {
		return $this->terms->lookup( $provider, $optionType, $sourceTerm );
	}

	public function confirm(
		string $provider,
		string $optionType,
		string $sourceTerm,
		string $canonicalTerm,
	): void {
		$this->terms->upsert( $provider, $optionType, $sourceTerm, $canonicalTerm, 'confirmed' );
	}

	/**
	 * Look for an existing mapping first, fall back to fuzzy guess.
	 *
	 * @param string[] $canonicalKnownTerms terms across the master catalog
	 *                                      that this might match (admin
	 *                                      typically supplies the set of
	 *                                      canonical colors / sizes etc.)
	 *
	 * @return array{term: ?string, confidence: string}
	 */
	public function suggest(
		string $provider,
		string $optionType,
		string $sourceTerm,
		array $canonicalKnownTerms,
	): array {
		$existing = $this->terms->lookup( $provider, $optionType, $sourceTerm );
		if ( $existing !== null ) {
			return [ 'term' => $existing, 'confidence' => 'confirmed' ];
		}

		$best = $this->fuzzyMatch( $sourceTerm, $canonicalKnownTerms );
		if ( $best === null ) {
			return [ 'term' => null, 'confidence' => 'unknown' ];
		}

		[ $candidate, $score ] = $best;
		if ( $score >= self::AUTO_THRESHOLD ) {
			$this->terms->upsert( $provider, $optionType, $sourceTerm, $candidate, 'auto' );
			return [ 'term' => $candidate, 'confidence' => 'auto' ];
		}

		return [ 'term' => null, 'confidence' => 'unknown' ];
	}

	public function forget( string $provider, string $optionType, string $sourceTerm ): void {
		$this->terms->delete( $provider, $optionType, $sourceTerm );
	}

	// ─── Internals ───────────────────────────────────────────────────────

	/**
	 * Score each candidate against the source term. Returns
	 * [ candidate, score 0..1 ] for the best match, or null if there are
	 * no candidates.
	 *
	 * @param string[] $candidates
	 * @return array{0:string,1:float}|null
	 */
	private function fuzzyMatch( string $source, array $candidates ): ?array {
		if ( ! $candidates ) return null;
		$best_score = 0.0;
		$best_term  = null;
		$source_l   = strtolower( trim( $source ) );

		foreach ( $candidates as $cand ) {
			$cand_l = strtolower( trim( $cand ) );
			if ( $cand_l === $source_l ) return [ $cand, 1.0 ];

			// Substring match — "ocean blue" ⊃ "blue"
			if ( str_contains( $source_l, $cand_l ) || str_contains( $cand_l, $source_l ) ) {
				$score = 0.85;
			} else {
				// Normalized Levenshtein: 1 - dist / max(len_a, len_b)
				$dist = levenshtein( $source_l, $cand_l );
				$max  = max( strlen( $source_l ), strlen( $cand_l ) ) ?: 1;
				$score = max( 0, 1 - $dist / $max );
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_term  = $cand;
			}
		}

		return $best_term === null ? null : [ $best_term, (float) $best_score ];
	}
}
