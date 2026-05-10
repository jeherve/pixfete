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
	 * The canonical SW path matches.
	 */
	public function test_matches_sw_path_accepts_canonical_path(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->assertTrue( PWA::matches_sw_path( '/pixfete-sw.js' ) );
	}

	/**
	 * A query string on the SW URL must not break the match — clients
	 * sometimes append `?ver=...` cache busters or build hashes.
	 */
	public function test_matches_sw_path_strips_query_string(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->assertTrue( PWA::matches_sw_path( '/pixfete-sw.js?ver=123' ) );
	}

	/**
	 * Unrelated paths do not match.
	 */
	public function test_matches_sw_path_rejects_other_paths(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

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
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
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
