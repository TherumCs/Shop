<?php
/**
 * Shop by Therum — REST: PSP webhook endpoint.
 *
 *   POST /shop/v1/webhooks/{provider}
 *
 * Single entry point for every PSP. The provider slug must match the
 * gateway's id(). Raw body is forwarded to WebhookReceiver for signature
 * verification + idempotent processing.
 *
 * Auth: none (PSPs don't have nonces). Signature verification IS the auth.
 * Each gateway's verifyWebhook() rejects forgeries.
 */

namespace Shop\Rest;

use Shop\Services\WebhookReceiver;

if ( ! defined( 'ABSPATH' ) ) exit;

final class WebhookController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly WebhookReceiver $receiver,
	) {}

	public function register(): void {
		register_rest_route( self::NAMESPACE, '/webhooks/(?P<provider>[a-z0-9_-]+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( \WP_REST_Request $req ): \WP_REST_Response {
		$provider = (string) $req->get_param( 'provider' );
		$body     = $req->get_body();
		$headers  = [];
		foreach ( $req->get_headers() as $name => $values ) {
			$headers[ strtolower( $name ) ] = is_array( $values ) ? ( $values[0] ?? '' ) : (string) $values;
		}
		$out = $this->receiver->handle( $provider, $body, $headers );
		return new \WP_REST_Response( $out['body'], $out['status'] );
	}
}
