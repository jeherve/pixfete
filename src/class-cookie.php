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
}
