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
		$json      = wp_json_encode( $payload, JSON_THROW_ON_ERROR );
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

	/**
	 * Get the cookie name for a specific event page.
	 *
	 * Each event page gets its own cookie so guests can be authenticated
	 * independently per event. The name uses a consistent prefix to make
	 * cookies identifiable in the browser.
	 *
	 * @param int $page_id The WordPress page ID for the event.
	 * @return string Cookie name in the format `egps_{page_id}`.
	 */
	public static function cookie_name( int $page_id ): string {
		return 'egps_' . $page_id;
	}

	/**
	 * Compute a deterministic guest identifier from a cookie payload.
	 *
	 * Creates a SHA-256 hash from the guest name, registration timestamp,
	 * and page ID. This produces a stable, unique-per-guest identifier
	 * used for per-guest upload counting without storing personal data.
	 *
	 * @param array<string, mixed> $payload The cookie payload containing
	 *                                      guest_name, registered_at, and page_id.
	 * @return string A 64-character hex SHA-256 hash.
	 */
	public static function guest_id( array $payload ): string {
		return hash(
			'sha256',
			$payload['guest_name'] . '|' . $payload['registered_at'] . '|' . $payload['page_id']
		);
	}

	/**
	 * Read and verify the guest cookie for a specific event page.
	 *
	 * Looks up the cookie by name from `$_COOKIE`, verifies its HMAC
	 * signature and expiry, then confirms that the `page_id` in the
	 * payload matches the requested page. This prevents a cookie issued
	 * for one event from being reused on another.
	 *
	 * @param int $page_id The WordPress page ID to read the cookie for.
	 * @return array<string, mixed>|null The verified payload, or null if
	 *                                   absent, invalid, or page mismatch.
	 */
	public static function get_for_page( int $page_id ): ?array {
		$name = self::cookie_name( $page_id );

		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$raw     = wp_unslash( $_COOKIE[ $name ] );
		$payload = self::verify( $raw );

		if ( null === $payload ) {
			return null;
		}

		// Ensure the cookie belongs to this specific page.
		if ( $payload['page_id'] !== $page_id ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Set a signed guest cookie for an event page.
	 *
	 * Signs the payload and sends it as a browser cookie. The cookie uses
	 * path `/` so it's available across the site, `httponly` is false so
	 * the frontend JavaScript can read the guest name for UI display,
	 * `samesite` is Lax to allow normal navigation, and `secure` follows
	 * the current SSL state.
	 *
	 * The expiry can be customized via the `egps_cookie_expiry` filter.
	 *
	 * @param array<string, mixed> $payload The cookie payload to sign and set.
	 * @return void
	 */
	public static function set_for_page( array $payload ): void {
		$name   = self::cookie_name( $payload['page_id'] );
		$signed = self::sign( $payload );

		/**
		 * Filters the cookie expiration timestamp.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $expires_at Unix timestamp when the cookie expires.
		 * @param array $payload    The full cookie payload.
		 */
		$expires = (int) apply_filters( 'egps_cookie_expiry', $payload['expires_at'], $payload );

		// phpcs:ignore Jetpack.Functions.SetCookie.FoundNonHTTPOnlyFalse -- JS needs read access for state detection on mount.
		setcookie(
			$name,
			$signed,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly'  => false,
				'samesite' => 'Lax',
			)
		);
	}
}
