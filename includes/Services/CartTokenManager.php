<?php
/**
 * Shop by Therum — CartTokenManager.
 *
 * Owns the HTTP-only cookie that ties a browser to its cart session row.
 * Token is a random 32-byte hex string. No identity, no auth — just an
 * opaque pointer.
 *
 * Cookie name:    shop_cart_token
 * Lifetime:       30 days (rolling — touched on every request that uses it)
 * Path:           /
 * HttpOnly:       yes  (JS can't read it; that's fine — JS calls REST and
 *                       the cookie rides along)
 * SameSite:       Lax  (cart survives external redirects back to the store)
 * Secure:         yes if site is https
 */

namespace Shop\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartTokenManager {

	public const COOKIE      = 'shop_cart_token';
	public const LIFETIME    = 30 * DAY_IN_SECONDS;

	/**
	 * Get the current token from cookie, generating + setting one if absent.
	 * Safe to call on every request — write only happens on first call.
	 */
	public function current(): string {
		$existing = $_COOKIE[ self::COOKIE ] ?? '';
		if ( is_string( $existing ) && preg_match( '/^[a-f0-9]{32,64}$/', $existing ) ) {
			return $existing;
		}

		$token = bin2hex( random_bytes( 24 ) ); // 48 hex chars
		$this->set( $token );
		return $token;
	}

	/**
	 * Read-only check — returns null if the request has no token cookie yet.
	 * Useful for endpoints that must NOT create a cart (e.g. anonymous count).
	 */
	public function read(): ?string {
		$existing = $_COOKIE[ self::COOKIE ] ?? '';
		if ( is_string( $existing ) && preg_match( '/^[a-f0-9]{32,64}$/', $existing ) ) {
			return $existing;
		}
		return null;
	}

	public function set( string $token ): void {
		// Make the new value visible to the rest of this request too.
		$_COOKIE[ self::COOKIE ] = $token;

		if ( headers_sent() ) return;

		setcookie( self::COOKIE, $token, [
			'expires'  => time() + self::LIFETIME,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}

	public function clear(): void {
		unset( $_COOKIE[ self::COOKIE ] );
		if ( headers_sent() ) return;
		setcookie( self::COOKIE, '', [
			'expires'  => time() - 3600,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}
}
