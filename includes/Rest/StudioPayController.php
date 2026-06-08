<?php
/**
 * Counter by Therum — Studio Pay REST.
 *
 *   GET  /counter/v1/studio-pay/methods         public  — available methods
 *                                                     for the checkout strip
 *   GET  /counter/v1/admin/studio-pay/status    auth    — connect status per
 *                                                     provider
 *   POST /counter/v1/admin/studio-pay/cadence   auth    — set payout cadence
 *   POST /counter/v1/admin/studio-pay/payout    auth    — manual payout
 *   POST /counter/v1/admin/studio-pay/route     auth    — set per-method
 *                                                     provider override
 *
 * The /methods route is public because the checkout page calls it
 * before the customer is identified — it's safe (no PII, no money).
 */

namespace Counter\Rest;

use Counter\Payments\Studio\MethodRegistry;
use Counter\Payments\Studio\Payouts;
use Counter\Payments\Studio\StudioConnect;
use Counter\Payments\Studio\StudioPay;

if ( ! defined( 'ABSPATH' ) ) exit;

final class StudioPayController {

	public const NAMESPACE = 'counter/v1';

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

		// Method-toggle UI — one row per checkout method, on/off switch,
		// auto-routing baked in. The full picture for the Methods tab.
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/methods-overview', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'methodsOverview' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/method/toggle', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'toggleMethod' ],
			'permission_callback' => $auth,
		] );

		// Routing presets — one-click "Square-first" / "Stripe-first" setup
		// that writes the full method→provider map in one call.
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/routing/presets', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'listPresets' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/routing/preset', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'applyPreset' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/routing/flow', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'moneyFlow' ],
			'permission_callback' => $auth,
		] );

		// PayPal Smart Buttons config — checkout JS hits this to know
		// whether to mount PayPal/Venmo/PP-Credit buttons and which client
		// id + SDK URL to use. Public so the customer-facing checkout
		// can call it without admin caps.
		register_rest_route( self::NAMESPACE, '/studio-pay/paypal-config', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'paypalConfig' ],
			'permission_callback' => '__return_true',
		] );

		// Payout destinations — where Stripe sends the money. The merchant
		// picks one (usually their Square Debit Mastercard) and instant
		// payouts route there.
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/destinations', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'listDestinations' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/destinations/attach', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'attachDestination' ],
			'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/studio-pay/destinations/default', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'setDefaultDestination' ],
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
			wp_safe_redirect( admin_url( 'admin.php?page=counter-studio-pay&connected=' . urlencode( $id ) ) );
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
		$seen_ids = [];
		foreach ( $this->studio->providers() as $id => $p ) {
			$out[] = [
				'id'         => $id,
				'name'       => $p->displayName(),
				'connected'  => $p->isConnected(),
				'methods'    => $p->supportedMethods(),
				'balance'    => $p->isConnected() ? ( $p->availableBalance()?->amount ?? null ) : null,
				'source'     => 'studio',
			];
			$seen_ids[ $id ] = true;
		}

		// Surface payment connectors registered with Nexus. Shows up under
		// "Connected via Nexus" in the Providers tab. We only include those
		// whose config has been saved (treat that as "linked"); pure registry
		// presence isn't enough. Skip IDs that Studio Pay already owns so
		// the same provider doesn't appear twice.
		$out = array_merge( $out, $this->nexusPaymentProviders( $seen_ids ) );

		return new \WP_REST_Response( [
			'providers' => $out,
			'cadence'   => $this->payouts->cadence(),
			'routes'    => (array) get_option( 'counter_studio_pay_method_routes', [] ),
			'methods'   => MethodRegistry::all(),
		], 200 );
	}

	/**
	 * Pull payment-category connectors from Nexus that have configuration
	 * saved (i.e. the user actually linked them). Returns rows shaped like
	 * the Studio Pay provider list so the UI can render them uniformly.
	 *
	 * Silent no-op if Nexus isn't installed.
	 *
	 * @param array<string,true> $seen_ids IDs already covered by Studio
	 *                                     Pay's own providers — skipped.
	 * @return list<array<string,mixed>>
	 */
	private function nexusPaymentProviders( array $seen_ids ): array {
		if ( ! function_exists( 'nexus_connectors_by_category' )
			|| ! function_exists( 'nexus_connector_is_configured' )
		) {
			return [];
		}

		$out = [];
		foreach ( nexus_connectors_by_category( 'payments' ) as $id => $c ) {
			if ( isset( $seen_ids[ $id ] ) ) continue;
			if ( ! nexus_connector_is_configured( $id ) ) continue;

			$out[] = [
				'id'        => $id,
				'name'      => (string) ( $c['name'] ?? $id ),
				'connected' => true,
				'methods'   => isset( $c['methods'] ) && is_array( $c['methods'] )
					? $c['methods']
					: [],
				'balance'   => null,
				'source'    => 'nexus',
			];
		}
		return $out;
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
		$routes = (array) get_option( 'counter_studio_pay_method_routes', [] );
		if ( $provider === '' || $provider === 'auto' ) {
			unset( $routes[ $method ] );
		} else {
			$routes[ $method ] = $provider;
		}
		update_option( 'counter_studio_pay_method_routes', $routes );
		return new \WP_REST_Response( [ 'ok' => true, 'routes' => $routes ], 200 );
	}

	// ─── Method toggles (the simple Methods tab) ────────────────────────

	/**
	 * Everything the Methods tab needs in one shot:
	 *   - method id, label, group
	 *   - the provider that will handle it (auto-routed via preset)
	 *   - whether that provider is connected
	 *   - whether the merchant has it toggled on
	 *
	 * On first access, applies the Square-first preset silently so the
	 * merchant doesn't have to make routing decisions — they just flip
	 * methods on/off.
	 */
	public function methodsOverview( \WP_REST_Request $req ): \WP_REST_Response {
		// First-run silent routing: if no routes are saved yet, OR if the
		// schema bumped (new methods added that aren't in the saved routes
		// map), re-apply the active preset so the new methods route somewhere.
		$routes = (array) get_option( 'counter_studio_pay_method_routes', [] );
		$needsBackfill = empty( $routes );
		if ( ! $needsBackfill ) {
			foreach ( \Counter\Payments\Studio\MethodRegistry::all() as $m ) {
				if ( ! array_key_exists( $m['id'], $routes ) ) { $needsBackfill = true; break; }
			}
		}
		if ( $needsBackfill ) {
			$preset = \Counter\Payments\Studio\RoutingPresets::activePresetId() ?: 'square_first';
			\Counter\Payments\Studio\RoutingPresets::apply( $preset === 'custom' ? 'square_first' : $preset );
		}

		$routes  = (array) get_option( 'counter_studio_pay_method_routes', [] );
		$enabled = get_option( 'counter_studio_pay_methods_enabled', [] );
		if ( ! is_array( $enabled ) ) $enabled = [];

		$connections = [];
		foreach ( $this->studio->providers() as $id => $p ) {
			$connections[ $id ] = $p->isConnected();
		}

		$grouped = [];
		foreach ( \Counter\Payments\Studio\MethodRegistry::all() as $m ) {
			$provider = $routes[ $m['id'] ] ?? ( $m['providers'][0] ?? '' );
			$grouped[ $m['group'] ][] = [
				'id'                 => $m['id'],
				'label'              => $m['label'],
				'group'              => $m['group'],
				'provider'           => $provider,
				'provider_connected' => $connections[ $provider ] ?? false,
				'enabled'            => $enabled[ $m['id'] ] ?? true,
			];
		}

		return new \WP_REST_Response( [
			'groups' => $grouped,
		], 200 );
	}

	public function toggleMethod( \WP_REST_Request $req ): \WP_REST_Response {
		$method  = (string) ( $req->get_param( 'method' )  ?? '' );
		$enabled = (bool)   ( $req->get_param( 'enabled' ) ?? true );
		if ( $method === '' || \Counter\Payments\Studio\MethodRegistry::find( $method ) === null ) {
			return new \WP_REST_Response( [ 'error' => "Unknown method '$method'." ], 400 );
		}
		$current = (array) get_option( 'counter_studio_pay_methods_enabled', [] );
		$current[ $method ] = $enabled;
		update_option( 'counter_studio_pay_methods_enabled', $current );
		return new \WP_REST_Response( [ 'ok' => true, 'method' => $method, 'enabled' => $enabled ], 200 );
	}

	// ─── Routing presets ────────────────────────────────────────────────

	public function listPresets( \WP_REST_Request $req ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'presets'        => \Counter\Payments\Studio\RoutingPresets::all(),
			'active_preset'  => \Counter\Payments\Studio\RoutingPresets::activePresetId(),
			'current_routes' => (array) get_option( 'counter_studio_pay_method_routes', [] ),
		], 200 );
	}

	public function applyPreset( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (string) ( $req->get_param( 'id' ) ?? '' );
		$routes = \Counter\Payments\Studio\RoutingPresets::apply( $id );
		if ( $routes === null ) {
			return new \WP_REST_Response( [ 'error' => "Unknown preset '$id'." ], 400 );
		}
		return new \WP_REST_Response( [ 'ok' => true, 'routes' => $routes ], 200 );
	}

	/**
	 * Per-method money-flow summary. Joins:
	 *   MethodRegistry × current routes × provider connection × cadence
	 *     × payout-destination state → "where does each method's $ go?"
	 *
	 * Used by the Methods tab's "Money flow" panel to render the visual
	 * bucket counts + the per-method destination chips.
	 */
	public function moneyFlow( \WP_REST_Request $req ): \WP_REST_Response {
		$routes              = (array) get_option( 'counter_studio_pay_method_routes', [] );
		$cadence             = (string) get_option( 'counter_studio_pay_payout_cadence', 'daily' );
		$hasInstantToSquare  = (string) get_option( 'counter_studio_pay_payout_destination', '' ) !== '';

		$summary = \Counter\Payments\Studio\PaymentDestinations::summarize(
			$routes, $cadence, $hasInstantToSquare,
		);

		// Per-provider connection state so the UI can render "✓ connected"
		// alongside each destination chip.
		$connections = [];
		foreach ( $this->studio->providers() as $id => $p ) {
			$connections[ $id ] = $p->isConnected();
		}

		return new \WP_REST_Response( [
			'buckets'     => $summary['buckets'],
			'lines'       => $summary['lines'],
			'connections' => $connections,
			'cadence'     => $cadence,
			'instant_to_square_enabled' => $hasInstantToSquare,
		], 200 );
	}

	// ─── PayPal Smart Buttons ───────────────────────────────────────────

	public function paypalConfig( \WP_REST_Request $req ): \WP_REST_Response {
		$paypal = $this->studio->providers()['paypal'] ?? null;
		if ( ! $paypal instanceof \Counter\Payments\Providers\PayPalProvider || ! $paypal->isConnected() ) {
			return new \WP_REST_Response( [ 'connected' => false ], 200 );
		}
		return new \WP_REST_Response( [
			'connected' => true,
			'client_id' => $paypal->publicClientId(),
			'sdk_url'   => $paypal->smartButtonsSdkUrl(),
			// Funding sources the merchant has visible at checkout. Maps
			// directly to PayPal SDK's `fundingSource` parameter.
			'buttons'   => [ 'paypal', 'venmo', 'paylater' ],
		], 200 );
	}

	// ─── Payout destinations ────────────────────────────────────────────

	/**
	 * The Stripe provider is the only one that supports per-destination
	 * payouts today (Stripe Connect external_accounts). Helper keeps the
	 * destination endpoints from re-resolving it on every call.
	 */
	private function stripe(): ?\Counter\Payments\Providers\StripeProvider {
		$p = $this->studio->providers()['stripe'] ?? null;
		return $p instanceof \Counter\Payments\Providers\StripeProvider ? $p : null;
	}

	public function listDestinations( \WP_REST_Request $req ): \WP_REST_Response {
		$stripe = $this->stripe();
		if ( $stripe === null || ! $stripe->isConnected() ) {
			return new \WP_REST_Response( [
				'destinations'     => [],
				'default_id'       => '',
				'publishable_key'  => '',
				'connected'        => false,
			], 200 );
		}

		$default = (string) get_option( 'counter_studio_pay_payout_destination', '' );
		return new \WP_REST_Response( [
			'destinations'    => $stripe->listExternalAccounts(),
			'default_id'      => $default,
			'publishable_key' => $stripe->publishableKey(),
			'connected'       => true,
		], 200 );
	}

	/**
	 * Attach a tokenized debit card to the Stripe account, then mark it
	 * as the default payout destination. Token is generated client-side
	 * by Stripe.js (raw card numbers never touch our server).
	 */
	public function attachDestination( \WP_REST_Request $req ): \WP_REST_Response {
		$token = trim( (string) ( $req->get_param( 'token' ) ?? '' ) );
		if ( $token === '' ) {
			return new \WP_REST_Response( [ 'error' => 'Missing card token.' ], 400 );
		}
		$stripe = $this->stripe();
		if ( $stripe === null || ! $stripe->isConnected() ) {
			return new \WP_REST_Response( [ 'error' => 'Stripe not connected.' ], 400 );
		}
		try {
			$id = $stripe->attachExternalAccount( $token );
			if ( $id !== '' ) {
				update_option( 'counter_studio_pay_payout_destination', $id );
			}
			return new \WP_REST_Response( [ 'ok' => true, 'id' => $id ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	public function setDefaultDestination( \WP_REST_Request $req ): \WP_REST_Response {
		$id = trim( (string) ( $req->get_param( 'id' ) ?? '' ) );
		// Accept empty string to clear (fall back to Stripe default external).
		update_option( 'counter_studio_pay_payout_destination', $id );
		return new \WP_REST_Response( [ 'ok' => true, 'id' => $id ], 200 );
	}
}
