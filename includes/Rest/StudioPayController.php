<?php
/**
 * Shop by Therum — Studio Pay REST.
 *
 *   GET  /shop/v1/studio-pay/methods         public  — available methods
 *                                                     for the checkout strip
 *   GET  /shop/v1/admin/studio-pay/status    auth    — connect status per
 *                                                     provider
 *   POST /shop/v1/admin/studio-pay/cadence   auth    — set payout cadence
 *   POST /shop/v1/admin/studio-pay/payout    auth    — manual payout
 *   POST /shop/v1/admin/studio-pay/route     auth    — set per-method
 *                                                     provider override
 *
 * The /methods route is public because the checkout page calls it
 * before the customer is identified — it's safe (no PII, no money).
 */

namespace Shop\Rest;

use Shop\Payments\Studio\MethodRegistry;
use Shop\Payments\Studio\Payouts;
use Shop\Payments\Studio\StudioConnect;
use Shop\Payments\Studio\StudioPay;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StudioPayController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly StudioPay     $studio,
		private readonly Payouts       $payouts,
		private readonly StudioConnect $connect,
	) {}

	public function register(): void {
		$auth = fn(): bool => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		register_rest_route( self::NAMESPACE, '/studio-pay/methods', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'methods' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/admin/studio-pay/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'status' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/studio-pay/cadence', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'cadence' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/studio-pay/payout', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'payout' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/studio-pay/route', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'setRoute' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/studio-pay/connect/(?P<provider>[a-z]+)/start', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'connectStart' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/connect/(?P<provider>[a-z]+)/callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'connectCallback' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/connect/(?P<provider>[a-z]+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'disconnect' ],
			'permission_callback' => $auth,
		] );
	}

	public function connectStart( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$url = $this->connect->startUrl( (string) $req->get_param( 'provider' ) );
			return new \WP_REST_Response( [ 'redirect_url' => $url ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	public function connectCallback( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$id = $this->connect->finish( (string) $req->get_param( 'provider' ), $req->get_query_params() );
			// Redirect back to the admin page on success.
			wp_safe_redirect( admin_url( 'admin.php?page=shop-studio-pay&connected=' . urlencode( $id ) ) );
			exit;
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	public function disconnect( \WP_REST_Request $req ): \WP_REST_Response {
		$this->connect->disconnect( (string) $req->get_param( 'provider' ) );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function methods( \WP_REST_Request $req ): \WP_REST_Response {
		// Public — the checkout method strip queries this. Returns only
		// the methods that have a connected provider, grouped for UI
		// rendering convenience.
		$available = $this->studio->availableMethods();
		$grouped   = [ 'card' => [], 'wallets' => [], 'bnpl' => [], 'bank' => [], 'crypto' => [], 'p2p' => [] ];
		foreach ( $available as $m ) $grouped[ $m['group'] ][] = $m;
		return new \WP_REST_Response( [ 'methods' => $available, 'by_group' => $grouped ], 200 );
	}

	public function status( \WP_REST_Request $req ): \WP_REST_Response {
		$out = [];
		foreach ( $this->studio->providers() as $id => $p ) {
			$out[] = [
				'id'         => $id,
				'name'       => $p->displayName(),
				'connected'  => $p->isConnected(),
				'methods'    => $p->supportedMethods(),
				'balance'    => $p->isConnected() ? ( $p->availableBalance()?->amount ?? null ) : null,
			];
		}
		return new \WP_REST_Response( [
			'providers' => $out,
			'cadence'   => $this->payouts->cadence(),
			'routes'    => (array) get_option( 'shop_studio_pay_method_routes', [] ),
			'methods'   => MethodRegistry::all(),
		], 200 );
	}

	public function cadence( \WP_REST_Request $req ): \WP_REST_Response {
		$value = (string) ( $req->get_param( 'value' ) ?? '' );
		try {
			$this->payouts->setCadence( $value );
			return new \WP_REST_Response( [ 'ok' => true, 'cadence' => $value ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	public function payout( \WP_REST_Request $req ): \WP_REST_Response {
		$instant = (bool) ( $req->get_param( 'instant' ) ?? false );
		$results = $this->payouts->payoutAll( $instant );
		return new \WP_REST_Response( [ 'results' => $results ], 200 );
	}

	public function setRoute( \WP_REST_Request $req ): \WP_REST_Response {
		$method   = (string) ( $req->get_param( 'method' )   ?? '' );
		$provider = (string) ( $req->get_param( 'provider' ) ?? '' );
		if ( MethodRegistry::find( $method ) === null ) {
			return new \WP_REST_Response( [ 'error' => "Unknown method '$method'." ], 400 );
		}
		$routes = (array) get_option( 'shop_studio_pay_method_routes', [] );
		if ( $provider === '' || $provider === 'auto' ) {
			unset( $routes[ $method ] );
		} else {
			$routes[ $method ] = $provider;
		}
		update_option( 'shop_studio_pay_method_routes', $routes );
		return new \WP_REST_Response( [ 'ok' => true, 'routes' => $routes ], 200 );
	}
}
