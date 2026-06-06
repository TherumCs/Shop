<?php
/**
 * Counter by Therum — REST: Taxonomy reordering.
 *
 * Routes for drag-drop term ordering (hierarchical, atomic).
 */

namespace Counter\Rest;

use Counter\Repositories\TaxonomyOrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TaxonomyOrderController {

	public function __construct(
		private readonly TaxonomyOrderRepository $orders,
	) {}

	public const NAMESPACE = 'counter/v1';

	public function register(): void {
		$auth = fn(): bool => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		// GET /admin/taxonomies/{type} — fetch tree for admin UI
		register_rest_route( self::NAMESPACE, '/admin/taxonomies/(?P<type>[a-z_]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'getTree' ],
			'permission_callback' => $auth,
			'args'                => [
				'type' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// POST /admin/taxonomies/{type} — batch reorder
		register_rest_route( self::NAMESPACE, '/admin/taxonomies/(?P<type>[a-z_]+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'reorder' ],
			'permission_callback' => $auth,
			'args'                => [
				'type'    => [ 'type' => 'string', 'required' => true ],
				'updates' => [ 'type' => 'object', 'required' => true ],
			],
		] );
	}

	/**
	 * Fetch the full hierarchy for a taxonomy.
	 *
	 * @return \WP_REST_Response
	 */
	public function getTree( \WP_REST_Request $req ): \WP_REST_Response {
		$type = (string) $req->get_param( 'type' );

		if ( ! $this->isValidTaxonomy( $type ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'invalid_taxonomy', 'message' => "Unknown taxonomy: $type" ],
				400
			);
		}

		$items = $this->orders->getTree( $type );
		$data = array_map( fn( $item ) => $item->toArray(), $items );

		return new \WP_REST_Response( [ 'items' => $data ], 200 );
	}

	/**
	 * Reorder multiple terms atomically.
	 *
	 * Request body: { "updates": { "123": { "position": 0, "parent_id": null }, ... } }
	 *
	 * @return \WP_REST_Response
	 */
	public function reorder( \WP_REST_Request $req ): \WP_REST_Response {
		$type = (string) $req->get_param( 'type' );
		$updates = (array) ( $req->get_json_params()['updates'] ?? [] );

		if ( ! $this->isValidTaxonomy( $type ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'invalid_taxonomy', 'message' => "Unknown taxonomy: $type" ],
				400
			);
		}

		if ( ! $updates ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_updates', 'message' => 'No updates provided' ],
				400
			);
		}

		// Validate all updates before processing
		foreach ( $updates as $termId => $data ) {
			if ( ! is_numeric( $termId ) || ! is_array( $data ) ) {
				return new \WP_REST_Response(
					[ 'code' => 'invalid_format', 'message' => 'Invalid update format' ],
					400
				);
			}
			if ( ! isset( $data['position'] ) || ! is_numeric( $data['position'] ) ) {
				return new \WP_REST_Response(
					[ 'code' => 'missing_position', 'message' => 'Missing position for term ' . $termId ],
					400
				);
			}
		}

		try {
			$this->orders->batchReorder( $type, $updates );
		} catch ( \DomainException $e ) {
			return new \WP_REST_Response(
				[ 'code' => 'taxonomy_error', 'message' => $e->getMessage() ],
				422
			);
		}

		/**
		 * Allow plugins to react to taxonomy reorder.
		 *
		 * @param string $taxonomy
		 * @param array $updates
		 */
		do_action( 'counter_taxonomy_reordered', $type, $updates );

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Whitelist of taxonomies that can be reordered.
	 */
	private function isValidTaxonomy( string $type ): bool {
		return in_array( $type, [
			'product_categories',
			'product_attributes',
			'product_variants',
			'vendors',
			'collections',
		], true );
	}
}
