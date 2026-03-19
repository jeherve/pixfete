<?php
/**
 * HMAC-signed cookie management for guest authentication.
 *
 * Provides stateless utilities for creating, signing, verifying, and
 * managing per-event-page guest cookies. Every REST endpoint depends
 * on this class for authentication.
 *
 * Cookie format: {base64url-encoded JSON payload}.{HMAC-SHA256 hex}
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

/**
 * Static utility class for HMAC-signed guest cookie operations.
 */
class Cookie {

	/**
	 * Sign a payload array into a cookie value string.
	 *
	 * JSON-encodes the payload, base64url-encodes it, then computes an
	 * HMAC-SHA256 signature using the WordPress auth salt. Returns the
	 * two parts joined by a dot: `{base64url}.{hmac_hex}`.
	 *
	 * @param array<string, mixed> $payload The cookie payload data.
	 * @return string Signed cookie value in `{base64url}.{hmac_hex}` format.
	 */
	public static function sign( array $payload ): string {
		$json      = wp_json_encode( $payload );
		$base64url = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$hmac      = hash_hmac( 'sha256', $base64url, wp_salt( 'auth' ) );

		return $base64url . '.' . $hmac;
	}

	/**
	 * Verify a signed cookie string and return the decoded payload.
	 *
	 * Validates the cookie format (exactly 2 dot-separated parts),
	 * recomputes the HMAC using `hash_equals()` for timing-safe comparison,
	 * decodes the JSON payload, and checks the `expires_at` timestamp.
	 *
	 * Returns null on any failure: bad format, HMAC mismatch, invalid JSON,
	 * missing `expires_at`, or expiry in the past.
	 *
	 * @param string $cookie_value Raw cookie string from the browser.
	 * @return array<string, mixed>|null Decoded payload array, or null on failure.
	 */
	public static function verify( string $cookie_value ): ?array {
		// Must have exactly two dot-separated parts.
		$parts = explode( '.', $cookie_value );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		list( $base64url, $hmac ) = $parts;

		if ( '' === $base64url || '' === $hmac ) {
			return null;
		}

		// Timing-safe HMAC comparison.
		$expected_hmac = hash_hmac( 'sha256', $base64url, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected_hmac, $hmac ) ) {
			return null;
		}

		// Decode the base64url payload.
		$base64  = strtr( $base64url, '-_', '+/' );
		$json    = base64_decode( $base64, true );
		if ( false === $json ) {
			return null;
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		// Check expiration.
		if ( ! isset( $payload['expires_at'] ) || $payload['expires_at'] < time() ) {
			return null;
		}

		return $payload;
	}
}
