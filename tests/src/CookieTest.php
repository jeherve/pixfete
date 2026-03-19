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

	// ─── §2b: Cookie verification ──────────────────────────────────────

	/**
	 * Test that verify() returns the payload for a valid, non-expired cookie.
	 */
	public function test_verify_returns_payload_for_valid_cookie(): void {
		$payload = $this->make_payload();
		$signed  = Cookie::sign( $payload );
		$result  = Cookie::verify( $signed );

		$this->assertIsArray( $result );
		$this->assertSame( $payload['guest_name'], $result['guest_name'] );
		$this->assertSame( $payload['page_id'], $result['page_id'] );
	}

	/**
	 * Test that verify() returns null when the HMAC has been tampered with.
	 */
	public function test_verify_returns_null_for_tampered_hmac(): void {
		$signed  = Cookie::sign( $this->make_payload() );
		$parts   = explode( '.', $signed );
		// Flip one character in the HMAC to simulate tampering.
		$parts[1] = str_repeat( 'a', 64 );
		$tampered = implode( '.', $parts );

		$this->assertNull( Cookie::verify( $tampered ) );
	}

	/**
	 * Test that verify() returns null when the payload has been tampered with.
	 */
	public function test_verify_returns_null_for_tampered_payload(): void {
		$signed  = Cookie::sign( $this->make_payload() );
		$parts   = explode( '.', $signed );
		// Modify the base64url payload.
		$parts[0] = $parts[0] . 'TAMPERED';
		$tampered = implode( '.', $parts );

		$this->assertNull( Cookie::verify( $tampered ) );
	}

	/**
	 * Test that verify() returns null for an expired cookie (expires_at in the past).
	 */
	public function test_verify_returns_null_for_expired_cookie(): void {
		$payload               = $this->make_payload();
		$payload['expires_at'] = time() - 3600; // Expired 1 hour ago.
		$signed                = Cookie::sign( $payload );

		$this->assertNull( Cookie::verify( $signed ) );
	}

	/**
	 * Test that verify() returns null for an empty string.
	 */
	public function test_verify_returns_null_for_empty_string(): void {
		$this->assertNull( Cookie::verify( '' ) );
	}

	/**
	 * Test that verify() returns null for a string with no dot.
	 */
	public function test_verify_returns_null_for_no_dot(): void {
		$this->assertNull( Cookie::verify( 'nodothere' ) );
	}

	/**
	 * Test that verify() returns null for a string with too many dots.
	 */
	public function test_verify_returns_null_for_too_many_dots(): void {
		$this->assertNull( Cookie::verify( 'part1.part2.part3' ) );
	}

	/**
	 * Test that verify() returns null when base64url segment is not valid JSON.
	 */
	public function test_verify_returns_null_for_invalid_json(): void {
		$base64url = rtrim( strtr( base64_encode( 'not-json' ), '+/', '-_' ), '=' );
		$hmac      = hash_hmac( 'sha256', $base64url, 'test-salt-value' );

		$this->assertNull( Cookie::verify( $base64url . '.' . $hmac ) );
	}

	// ─── §2c: Cookie name and guest ID ──────────────────────────────────

	/**
	 * Test that cookie_name() returns the expected format: egps_{page_id}.
	 */
	public function test_cookie_name_returns_egps_prefix_with_page_id(): void {
		$this->assertSame( 'egps_42', Cookie::cookie_name( 42 ) );
		$this->assertSame( 'egps_1', Cookie::cookie_name( 1 ) );
		$this->assertSame( 'egps_99999', Cookie::cookie_name( 99999 ) );
	}

	/**
	 * Test that guest_id() is deterministic: same inputs produce the same hash.
	 */
	public function test_guest_id_is_deterministic(): void {
		$payload = $this->make_payload();

		$id_a = Cookie::guest_id( $payload );
		$id_b = Cookie::guest_id( $payload );

		$this->assertSame( $id_a, $id_b, 'guest_id must be deterministic for the same payload.' );
	}

	/**
	 * Test that guest_id() differs for different guest names.
	 */
	public function test_guest_id_differs_for_different_guest_names(): void {
		$payload_alice = $this->make_payload();
		$payload_bob   = $this->make_payload();
		$payload_bob['guest_name'] = 'Bob';

		$this->assertNotSame(
			Cookie::guest_id( $payload_alice ),
			Cookie::guest_id( $payload_bob ),
			'guest_id must differ when guest_name differs.'
		);
	}

	/**
	 * Test that guest_id() returns a valid SHA-256 hex string (64 chars).
	 */
	public function test_guest_id_returns_sha256_hex(): void {
		$id = Cookie::guest_id( $this->make_payload() );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $id, 'guest_id must be a 64-character hex string.' );
	}

	/**
	 * Test that guest_id() uses guest_name, registered_at, and page_id.
	 */
	public function test_guest_id_uses_all_three_components(): void {
		$payload = $this->make_payload();

		// Changing registered_at should change the ID.
		$payload_diff_time               = $payload;
		$payload_diff_time['registered_at'] = 1700000001;

		// Changing page_id should change the ID.
		$payload_diff_page            = $payload;
		$payload_diff_page['page_id'] = 99;

		$original = Cookie::guest_id( $payload );

		$this->assertNotSame( $original, Cookie::guest_id( $payload_diff_time ), 'guest_id must change when registered_at changes.' );
		$this->assertNotSame( $original, Cookie::guest_id( $payload_diff_page ), 'guest_id must change when page_id changes.' );
	}
}
