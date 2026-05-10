<?php
/**
 * Tests for Pixfête PWA service worker routing.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Cover the PWA helper class.
 *
 * The production `maybe_serve()` ends with `exit`, which can't be
 * cleanly intercepted under PHPUnit. To keep the suite green and the
 * matcher under coverage, tests target the public `matches_sw_path()`
 * seam and the early-return path of `maybe_serve()` (which never
 * reaches the `exit`).
 */
final class PwaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * Stub the WP helpers the matcher needs so each test stays focused
	 * on path comparison rather than on Brain\Monkey ceremony.
	 *
	 * @param string $home_url The full home URL to return from `home_url()`.
	 */
	private function stub_url_helpers( string $home_url ): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias(
			static function ( string $path = '' ) use ( $home_url ): string {
				return rtrim( $home_url, '/' ) . $path;
			}
		);
	}

	/**
	 * The canonical SW path matches on a root install.
	 */
	public function test_matches_sw_path_accepts_canonical_path(): void {
		$this->stub_url_helpers( 'https://example.test' );

		$this->assertTrue( PWA::matches_sw_path( '/pixfete-sw.js' ) );
	}

	/**
	 * A query string on the SW URL must not break the match — clients
	 * sometimes append `?ver=...` cache busters or build hashes.
	 */
	public function test_matches_sw_path_strips_query_string(): void {
		$this->stub_url_helpers( 'https://example.test' );

		$this->assertTrue( PWA::matches_sw_path( '/pixfete-sw.js?ver=123' ) );
	}

	/**
	 * Subdirectory installs (e.g. WordPress at `/blog/`) must match the
	 * prefixed path that `home_url()` produces — otherwise the SW URL
	 * 404s and Background Sync recovery is silently disabled.
	 */
	public function test_matches_sw_path_matches_subdirectory_install(): void {
		$this->stub_url_helpers( 'https://example.test/blog' );

		$this->assertTrue( PWA::matches_sw_path( '/blog/pixfete-sw.js' ) );
		$this->assertFalse( PWA::matches_sw_path( '/pixfete-sw.js' ) );
	}

	/**
	 * Unrelated paths do not match.
	 */
	public function test_matches_sw_path_rejects_other_paths(): void {
		$this->stub_url_helpers( 'https://example.test' );

		$this->assertFalse( PWA::matches_sw_path( '/wp-admin/' ) );
		$this->assertFalse( PWA::matches_sw_path( '/' ) );
		$this->assertFalse( PWA::matches_sw_path( '/some/pixfete-sw.js' ) );
	}

	/**
	 * Empty input (e.g. CLI execution where REQUEST_URI is unset) is
	 * handled without warnings and returns false.
	 */
	public function test_matches_sw_path_rejects_empty_input(): void {
		$this->assertFalse( PWA::matches_sw_path( '' ) );
	}

	/**
	 * Non-matching requests fall through silently — `maybe_serve()` must
	 * not emit headers or set a status when the URL doesn't match.
	 */
	public function test_maybe_serve_returns_early_for_other_paths(): void {
		$_SERVER['REQUEST_URI'] = '/wp-admin/';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$this->stub_url_helpers( 'https://example.test' );
		Functions\expect( 'status_header' )->never();

		PWA::maybe_serve();
		$this->assertTrue( true );
	}

	/**
	 * Missing REQUEST_URI is handled without warnings.
	 */
	public function test_maybe_serve_returns_early_when_request_uri_absent(): void {
		Functions\expect( 'status_header' )->never();

		PWA::maybe_serve();
		$this->assertTrue( true );
	}
}
