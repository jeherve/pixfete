<?php
/**
 * Tests for the Cookie class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Cookie;
use PHPUnit\Framework\TestCase;

/**
 * Test the Cookie class methods for HMAC-signed guest cookies.
 */
class CookieTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub WordPress functions used across tests.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value' );
	}

	/**
	 * Tear down Brain Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper to build a valid payload for tests.
	 *
	 * @return array<string, mixed>
	 */
	private function make_payload(): array {
		return array(
			'page_id'       => 42,
			'event_version' => 1,
			'guest_name'    => 'Alice',
			'table_name'    => 'Table 5',
			'consent'       => true,
			'registered_at' => 1700000000,
			'expires_at'    => time() + 86400,
		);
	}

	// ─── §2a: Cookie signing and serialization ──────────────────────────

	/**
	 * Test that sign() produces a dot-separated string with exactly 2 parts.
	 */
	public function test_sign_produces_base64url_dot_hmac_format(): void {
		$signed = Cookie::sign( $this->make_payload() );

		$parts = explode( '.', $signed );
		$this->assertCount( 2, $parts, 'Signed cookie must have exactly two dot-separated parts.' );
		$this->assertNotEmpty( $parts[0], 'Base64url segment must not be empty.' );
		$this->assertNotEmpty( $parts[1], 'HMAC segment must not be empty.' );
	}

	/**
	 * Test that sign() produces different HMACs when the salt changes,
	 * proving that wp_salt('auth') is used as the HMAC key.
	 */
	public function test_sign_uses_wp_salt_auth_for_hmac(): void {
		$payload = $this->make_payload();

		// First signing with default salt ('test-salt-value' from setUp).
		$signed_a = Cookie::sign( $payload );
		$hmac_a   = explode( '.', $signed_a )[1];

		// Manually compute expected HMAC to verify the salt is used.
		$json      = json_encode( $payload );
		$base64url = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$expected  = hash_hmac( 'sha256', $base64url, 'test-salt-value' );

		$this->assertSame( $expected, $hmac_a, 'HMAC must be computed using the value from wp_salt("auth").' );
	}

	/**
	 * Test that the base64url segment decodes to the original payload JSON.
	 */
	public function test_sign_base64url_decodes_to_payload_json(): void {
		$payload = $this->make_payload();
		$signed  = Cookie::sign( $payload );

		$parts      = explode( '.', $signed );
		$base64url  = $parts[0];
		// Reverse base64url encoding.
		$base64     = strtr( $base64url, '-_', '+/' );
		$json       = base64_decode( $base64, true );
		$decoded    = json_decode( $json, true );

		$this->assertSame( $payload, $decoded, 'Base64url segment must decode to the original payload.' );
	}

	/**
	 * Test that the HMAC segment is a valid hex string (SHA-256 = 64 hex chars).
	 */
	public function test_sign_hmac_is_valid_hex(): void {
		$signed = Cookie::sign( $this->make_payload() );
		$parts  = explode( '.', $signed );
		$hmac   = $parts[1];

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hmac, 'HMAC must be a 64-character hex string.' );
	}
}
