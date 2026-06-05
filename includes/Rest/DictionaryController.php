<?php
/**
 * Shop by Therum — REST: vendor dictionary admin endpoints.
 *
 * Routes (namespace shop/v1):
 *
 *   GET    /admin/dictionary               list (optional ?provider= &option_type= &confidence=)
 *   POST   /admin/dictionary/confirm       confirm a mapping
 *   POST   /admin/dictionary/suggest       returns the best canonical term
 *                                          for a given source term
 *   DELETE /admin/dictionary               { provider, option_type, source_term }
 *
 * Gated on manage_woocommerce / manage_options.
 */

namespace Shop\Rest;

use Shop\Repositories\VendorOptionTermsRepository;
use Shop\Services\VendorDictionaryService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DictionaryController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly VendorDictionaryService $dictionary,
		private readonly VendorOptionTermsRepository $terms,
	) {}

	public function register(): void {
		$auth = function (): bool {
			return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		};

		register_rest_route( self::NAMESPACE, '/admin/dictionary', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list' ],
				'permission_callback' => $auth,
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $auth,
				'args' => [
					'provider'    => [ 'type' => 'string', 'required' => true ],
					'option_type' => [ 'type' => 'string', 'required' => true ],
					'source_term' => [ 'type' => 'string', 'required' => true ],
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/dictionary/confirm', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'confirm' ],
			'permission_callback' => $auth,
			'args' => [
				'provider'       => [ 'type' => 'string', 'required' => true ],
				'option_type'    => [ 'type' => 'string', 'required' => true ],
				'source_term'    => [ 'type' => 'string', 'required' => true ],
				'canonical_term' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/admin/dictionary/suggest', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'suggest' ],
			'permission_callback' => $auth,
			'args' => [
				'provider'    => [ 'type' => 'string',   'required' => true ],
				'option_type' => [ 'type' => 'string',   'required' => true ],
				'source_term' => [ 'type' => 'string',   'required' => true ],
				'candidates'  => [ 'type' => 'array',    'required' => true ],
			],
		] );
	}

	public function list( \WP_REST_Request $req ): \WP_REST_Response {
		$rows = $this->terms->list(
			provider:   $req->get_param( 'provider' )    ? (string) $req->get_param( 'provider' )    : null,
			optionType: $req->get_param( 'option_type' ) ? (string) $req->get_param( 'option_type' ) : null,
			confidence: $req->get_param( 'confidence' )  ? (string) $req->get_param( 'confidence' )  : null,
		);
		return new \WP_REST_Response( [ 'rows' => $rows ], 200 );
	}

	public function confirm( \WP_REST_Request $req ): \WP_REST_Response {
		$this->dictionary->confirm(
			provider:      (string) $req->get_param( 'provider' ),
			optionType:    (string) $req->get_param( 'option_type' ),
			sourceTerm:    (string) $req->get_param( 'source_term' ),
			canonicalTerm: (string) $req->get_param( 'canonical_term' ),
		);
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function suggest( \WP_REST_Request $req ): \WP_REST_Response {
		$candidates = (array) $req->get_param( 'candidates' );
		$candidates = array_values( array_filter( array_map( 'strval', $candidates ) ) );
		$result = $this->dictionary->suggest(
			provider:             (string) $req->get_param( 'provider' ),
			optionType:           (string) $req->get_param( 'option_type' ),
			sourceTerm:           (string) $req->get_param( 'source_term' ),
			canonicalKnownTerms:  $candidates,
		);
		return new \WP_REST_Response( $result, 200 );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$this->dictionary->forget(
			provider:   (string) $req->get_param( 'provider' ),
			optionType: (string) $req->get_param( 'option_type' ),
			sourceTerm: (string) $req->get_param( 'source_term' ),
		);
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}
}
