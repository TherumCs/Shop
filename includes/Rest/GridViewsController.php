<?php
/**
 * Shop by Therum — REST: grid views.
 *
 * Saved view = a named bundle of { columns, filters, sort } scoped to
 * one grid ("products" / "orders") and one user. Stored in user_meta
 * under `shop_grid_views_{grid}` as a JSON-encoded array.
 *
 *   GET    /shop/v1/admin/grid-views/{grid}        list this user's views
 *   POST   /shop/v1/admin/grid-views/{grid}        save new view
 *   PUT    /shop/v1/admin/grid-views/{grid}/{id}   update
 *   DELETE /shop/v1/admin/grid-views/{grid}/{id}   delete
 *
 * The id is a per-user incrementing int stored on the array itself —
 * keeps views local to user_meta without needing a new SQLite table.
 */

namespace Shop\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GridViewsController {

	public const NAMESPACE = 'shop/v1';

	public function register(): void {
		$auth = fn(): bool => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		register_rest_route( self::NAMESPACE, '/admin/grid-views/(?P<grid>[a-z\-]+)', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list' ],   'permission_callback' => $auth ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( self::NAMESPACE, '/admin/grid-views/(?P<grid>[a-z\-]+)/(?P<id>\d+)', [
			[ 'methods' => 'PUT',    'callback' => [ $this, 'update' ], 'permission_callback' => $auth ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete' ], 'permission_callback' => $auth ],
		] );
	}

	public function list( \WP_REST_Request $req ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'views' => $this->readAll( (string) $req->get_param( 'grid' ) ) ], 200 );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$grid    = (string) $req->get_param( 'grid' );
		$body    = $req->get_json_params() ?: [];
		$name    = (string) ( $body['name'] ?? '' );
		$config  = is_array( $body['config'] ?? null ) ? $body['config'] : [];
		if ( $name === '' ) return new \WP_REST_Response( [ 'error' => 'Name required.' ], 400 );

		$views = $this->readAll( $grid );
		$id    = ( max( array_column( $views, 'id' ) ?: [ 0 ] ) ) + 1;
		$view  = [ 'id' => $id, 'name' => $name, 'config' => $config, 'created_at' => time() ];
		$views[] = $view;
		$this->writeAll( $grid, $views );
		return new \WP_REST_Response( [ 'view' => $view ], 200 );
	}

	public function update( \WP_REST_Request $req ): \WP_REST_Response {
		$grid  = (string) $req->get_param( 'grid' );
		$id    = (int)    $req->get_param( 'id' );
		$body  = $req->get_json_params() ?: [];
		$views = $this->readAll( $grid );
		$changed = false;
		foreach ( $views as &$v ) {
			if ( $v['id'] === $id ) {
				if ( isset( $body['name'] ) )   $v['name']   = (string) $body['name'];
				if ( isset( $body['config'] ) ) $v['config'] = (array)  $body['config'];
				$changed = true;
			}
		}
		unset( $v );
		if ( ! $changed ) return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		$this->writeAll( $grid, $views );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$grid  = (string) $req->get_param( 'grid' );
		$id    = (int)    $req->get_param( 'id' );
		$views = $this->readAll( $grid );
		$views = array_values( array_filter( $views, fn( $v ) => $v['id'] !== $id ) );
		$this->writeAll( $grid, $views );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/** @return array<int, array<string,mixed>> */
	private function readAll( string $grid ): array {
		$raw = get_user_meta( get_current_user_id(), 'shop_grid_views_' . $this->safe( $grid ), true );
		$arr = $raw ? json_decode( (string) $raw, true ) : [];
		return is_array( $arr ) ? $arr : [];
	}

	/** @param array<int, array<string,mixed>> $views */
	private function writeAll( string $grid, array $views ): void {
		update_user_meta( get_current_user_id(), 'shop_grid_views_' . $this->safe( $grid ), wp_json_encode( $views ) );
	}

	private function safe( string $grid ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $grid ) );
	}
}
