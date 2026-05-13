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
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'status_header' )->never();

		PWA::maybe_serve();
		$this->assertTrue( true );
	}

	/**
	 * Missing REQUEST_URI is handled without warnings.
	 */
	public function test_maybe_serve_returns_early_when_request_uri_absent(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'status_header' )->never();

		PWA::maybe_serve();
		$this->assertTrue( true );
	}

	/**
	 * When the `pixfete_serve_service_worker` filter returns false,
	 * Pixfête steps aside: matcher fallback still works (so URL checks
	 * don't crash) but maybe_serve never emits anything. This lets
	 * other PWA plugins (Super PWA, OneSignal, Jetpack Boost) own the
	 * origin scope without forking Pixfête.
	 */
	public function test_maybe_serve_steps_aside_when_filter_disables(): void {
		$_SERVER['REQUEST_URI'] = '/pixfete-sw.js';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$this->stub_url_helpers( 'https://example.test' );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, bool $value ): bool {
				return 'pixfete_serve_service_worker' === $hook ? false : $value;
			}
		);
		Functions\expect( 'status_header' )->never();

		PWA::maybe_serve();
		$this->assertTrue( true );
	}

	/**
	 * `is_enabled()` defaults to true and respects the filter.
	 */
	public function test_is_enabled_respects_filter(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->assertTrue( PWA::is_enabled() );

		Functions\when( 'apply_filters' )->justReturn( false );
		$this->assertFalse( PWA::is_enabled() );
	}

	/**
	 * `is_manifest_enabled()` defaults to true so the manifest ships out of the box.
	 */
	public function test_is_manifest_enabled_defaults_true(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->assertTrue( PWA::is_manifest_enabled() );
	}

	/**
	 * Hosts can disable the manifest via the `pixfete_serve_manifest` filter
	 * without disabling the Service Worker — they're independently controlled.
	 */
	public function test_is_manifest_enabled_respects_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, bool $value ): bool {
				return 'pixfete_serve_manifest' === $hook ? false : $value;
			}
		);
		$this->assertFalse( PWA::is_manifest_enabled() );
	}

	/**
	 * `sw_scope()` returns `/` on a root install — the default SW scope.
	 */
	public function test_sw_scope_root_install(): void {
		$this->stub_url_helpers( 'https://example.test' );

		$this->assertSame( '/', PWA::sw_scope() );
	}

	/**
	 * `sw_scope()` returns the home URL path on subdirectory installs.
	 *
	 * Critical for subdirectory multisite: each subsite must register
	 * its SW under its own path scope so sibling sites on the same
	 * origin don't trample each other's registrations.
	 */
	public function test_sw_scope_subdirectory_install(): void {
		$this->stub_url_helpers( 'https://example.test/blog' );

		$this->assertSame( '/blog/', PWA::sw_scope() );
	}

	/**
	 * `sw_path()` returns the matcher's expected path so render.php
	 * and the matcher stay in lockstep.
	 */
	public function test_sw_path_matches_matcher_expectation(): void {
		$this->stub_url_helpers( 'https://example.test/blog' );

		$this->assertSame( '/blog/pixfete-sw.js', PWA::sw_path() );
	}

	/**
	 * Subdirectory-multisite simulation: site-A's SW path must not
	 * collide with site-B's. The matcher rejects the sibling path.
	 */
	public function test_matches_sw_path_rejects_sibling_subsite(): void {
		$this->stub_url_helpers( 'https://example.test/site-a' );

		$this->assertTrue( PWA::matches_sw_path( '/site-a/pixfete-sw.js' ) );
		$this->assertFalse( PWA::matches_sw_path( '/site-b/pixfete-sw.js' ) );
	}

	/**
	 * `short_name()` returns short titles unchanged.
	 */
	public function test_short_name_returns_short_title_unchanged(): void {
		$this->assertSame( 'Wedding', PWA::short_name( 'Wedding' ) );
		$this->assertSame( '', PWA::short_name( '' ) );
	}

	/**
	 * `short_name()` splits on `&` so "Sarah & Tom's Wedding" yields "Sarah".
	 */
	public function test_short_name_splits_on_ampersand(): void {
		$this->assertSame( 'Sarah', PWA::short_name( "Sarah & Tom's Wedding" ) );
	}

	/**
	 * `short_name()` splits on em-dash so "Sarah — Wedding" yields "Sarah".
	 */
	public function test_short_name_splits_on_em_dash(): void {
		$this->assertSame( 'Sarah', PWA::short_name( 'Sarah — Wedding' ) );
	}

	/**
	 * Multiple splitters in the same title pick the first segment.
	 */
	public function test_short_name_handles_mixed_splitters(): void {
		$this->assertSame( 'Sarah', PWA::short_name( 'Sarah & Tom — June 2026' ) );
	}

	/**
	 * A single long word is hard-truncated to 12 characters.
	 */
	public function test_short_name_hard_truncates_long_single_word(): void {
		$this->assertSame( 'AVeryLongWed', PWA::short_name( 'AVeryLongWeddingTitle' ) );
	}

	/**
	 * Whitespace alone is also a splitter, so "Some Very Long Title" gives "Some".
	 */
	public function test_short_name_splits_on_whitespace(): void {
		$this->assertSame( 'Some', PWA::short_name( 'Some Very Long Title' ) );
	}
}
