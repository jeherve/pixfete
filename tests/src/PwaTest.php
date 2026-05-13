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
	 * `manifest_path()` returns the canonical path on a root install.
	 */
	public function test_manifest_path_root_install(): void {
		$this->stub_url_helpers( 'https://example.test' );
		$this->assertSame( '/pixfete-123.webmanifest', PWA::manifest_path( 123 ) );
	}

	/**
	 * `manifest_path()` prefixes with the home URL path on subdirectory installs.
	 */
	public function test_manifest_path_subdirectory_install(): void {
		$this->stub_url_helpers( 'https://example.test/blog' );
		$this->assertSame( '/blog/pixfete-123.webmanifest', PWA::manifest_path( 123 ) );
	}

	/**
	 * `matches_manifest_path()` returns the post ID for a canonical match.
	 */
	public function test_matches_manifest_path_accepts_canonical_path(): void {
		$this->stub_url_helpers( 'https://example.test' );
		$this->assertSame( 123, PWA::matches_manifest_path( '/pixfete-123.webmanifest' ) );
	}

	/**
	 * Subdirectory installs match the prefixed path.
	 */
	public function test_matches_manifest_path_matches_subdirectory_install(): void {
		$this->stub_url_helpers( 'https://example.test/blog' );
		$this->assertSame( 123, PWA::matches_manifest_path( '/blog/pixfete-123.webmanifest' ) );
		$this->assertNull( PWA::matches_manifest_path( '/pixfete-123.webmanifest' ) );
	}

	/**
	 * Query strings on the manifest URL don't break the match.
	 */
	public function test_matches_manifest_path_strips_query_string(): void {
		$this->stub_url_helpers( 'https://example.test' );
		$this->assertSame( 123, PWA::matches_manifest_path( '/pixfete-123.webmanifest?ver=1' ) );
	}

	/**
	 * Non-numeric IDs (`/pixfete-foo.webmanifest`) are rejected — we
	 * never want to call `get_post()` with junk input.
	 */
	public function test_matches_manifest_path_rejects_non_numeric_id(): void {
		$this->stub_url_helpers( 'https://example.test' );
		$this->assertNull( PWA::matches_manifest_path( '/pixfete-foo.webmanifest' ) );
	}

	/**
	 * Zero and negative IDs are also rejected.
	 */
	public function test_matches_manifest_path_rejects_zero_and_negative(): void {
		$this->stub_url_helpers( 'https://example.test' );
		$this->assertNull( PWA::matches_manifest_path( '/pixfete-0.webmanifest' ) );
		$this->assertNull( PWA::matches_manifest_path( '/pixfete--5.webmanifest' ) );
	}

	/**
	 * Unrelated paths and empty input return null.
	 */
	public function test_matches_manifest_path_rejects_other_paths(): void {
		$this->stub_url_helpers( 'https://example.test' );
		$this->assertNull( PWA::matches_manifest_path( '/wp-admin/' ) );
		$this->assertNull( PWA::matches_manifest_path( '/pixfete-sw.js' ) );
		$this->assertNull( PWA::matches_manifest_path( '' ) );
	}

	/**
	 * Manifest dispatcher steps aside when `pixfete_serve_manifest`
	 * returns false — even though the URL matches.
	 */
	public function test_maybe_serve_skips_manifest_when_filter_disables(): void {
		$_SERVER['REQUEST_URI'] = '/pixfete-123.webmanifest';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$this->stub_url_helpers( 'https://example.test' );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'pixfete_serve_manifest' === $hook ) {
					return false;
				}
				return $value;
			}
		);
		Functions\expect( 'status_header' )->never();
		Functions\expect( 'get_post' )->never();

		PWA::maybe_serve();
		$this->assertTrue( true );
	}

	/**
	 * Manifest URL with no matching post returns 404 — `get_post()` null
	 * is a real "the post was deleted" case and we shouldn't serve a
	 * broken manifest for it.
	 *
	 * `status_header` is stubbed to throw so we can intercept the
	 * response path before the production `exit` halts PHPUnit.
	 */
	public function test_maybe_serve_returns_404_for_unknown_post(): void {
		$_SERVER['REQUEST_URI'] = '/pixfete-999.webmanifest';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$this->stub_url_helpers( 'https://example.test' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\expect( 'status_header' )->once()->with( 404 )->andThrow( new \RuntimeException( 'halt' ) );
		Functions\when( 'header' )->justReturn( null );

		try {
			PWA::maybe_serve();
		} catch ( \Throwable $e ) {
			unset( $e ); // Expected: status_header stub throws so we never hit exit().
		}
		$this->assertTrue( true );
	}

	/**
	 * Block-theme path: `wp_get_global_styles` returns a background color.
	 */
	public function test_resolve_theme_color_uses_block_theme_global_styles(): void {
		Functions\when( 'wp_get_global_styles' )->justReturn( array( 'color' => array( 'background' => '#abcdef' ) ) );
		Functions\when( 'get_background_color' )->justReturn( '' );

		$this->assertSame( '#abcdef', PWA::resolve_theme_color() );
	}

	/**
	 * Block-theme path normalizes 3-digit hex shorthand to 6-digit so the
	 * manifest is always #RRGGBB (some browsers reject the short form).
	 */
	public function test_resolve_theme_color_expands_shorthand_hex(): void {
		Functions\when( 'wp_get_global_styles' )->justReturn( array( 'color' => array( 'background' => '#abc' ) ) );
		Functions\when( 'get_background_color' )->justReturn( '' );

		$this->assertSame( '#aabbcc', PWA::resolve_theme_color() );
	}

	/**
	 * Classic theme fallback when block-theme path returns nothing usable.
	 */
	public function test_resolve_theme_color_falls_back_to_classic_background(): void {
		Functions\when( 'wp_get_global_styles' )->justReturn( array() );
		Functions\when( 'get_background_color' )->justReturn( 'fafafa' );

		$this->assertSame( '#fafafa', PWA::resolve_theme_color() );
	}

	/**
	 * Pixfête default kicks in when neither path yields a color.
	 * Hardcoded default is `#ffffff` (white).
	 */
	public function test_resolve_theme_color_defaults_to_white(): void {
		Functions\when( 'wp_get_global_styles' )->justReturn( array() );
		Functions\when( 'get_background_color' )->justReturn( '' );

		$this->assertSame( '#ffffff', PWA::resolve_theme_color() );
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

	/**
	 * `build_manifest()` produces a complete manifest for a post that has
	 * no featured image — icons fall back to the bundled Pixfête assets.
	 */
	public function test_build_manifest_uses_fallback_icons_without_featured_image(): void {
		$this->stub_url_helpers( 'https://example.test' );
		Functions\when( 'get_the_title' )->justReturn( "Sarah & Tom's Wedding" );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/wedding/' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_get_global_styles' )->justReturn( array() );
		Functions\when( 'get_background_color' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$manifest = PWA::build_manifest( 123 );

		$this->assertSame( "Sarah & Tom's Wedding", $manifest['name'] );
		$this->assertSame( 'Sarah', $manifest['short_name'] );
		$this->assertSame( 'https://example.test/wedding/', $manifest['start_url'] );
		$this->assertSame( 'https://example.test/wedding/', $manifest['scope'] );
		$this->assertSame( 'standalone', $manifest['display'] );
		$this->assertSame( '#ffffff', $manifest['theme_color'] );
		$this->assertSame( '#ffffff', $manifest['background_color'] );
		$this->assertCount( 3, $manifest['icons'] );
		$this->assertStringContainsString( 'icon-192.png', $manifest['icons'][0]['src'] );
		$this->assertSame( '192x192', $manifest['icons'][0]['sizes'] );
		$this->assertSame( 'image/png', $manifest['icons'][0]['type'] );
		$this->assertSame( 'any', $manifest['icons'][0]['purpose'] );
		$this->assertStringContainsString( 'icon-maskable-512.png', $manifest['icons'][2]['src'] );
		$this->assertSame( 'maskable', $manifest['icons'][2]['purpose'] );
	}

	/**
	 * Featured-image happy path: 192 and 512 variants exist, so icons
	 * point at them and maskable reuses the 512 source.
	 */
	public function test_build_manifest_uses_featured_image_when_available(): void {
		$this->stub_url_helpers( 'https://example.test' );
		Functions\when( 'get_the_title' )->justReturn( 'Wedding' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/wedding/' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
		Functions\when( 'wp_get_global_styles' )->justReturn( array() );
		Functions\when( 'get_background_color' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( int $id, string $size ): array {
				return array(
					"https://example.test/wp-content/uploads/{$size}.png",
					'pixfete-pwa-192' === $size ? 192 : 512,
					'pixfete-pwa-192' === $size ? 192 : 512,
					true,
				);
			}
		);

		$manifest = PWA::build_manifest( 123 );

		$this->assertCount( 3, $manifest['icons'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/pixfete-pwa-192.png', $manifest['icons'][0]['src'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/pixfete-pwa-512.png', $manifest['icons'][1]['src'] );
		$this->assertSame( 'maskable', $manifest['icons'][2]['purpose'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/pixfete-pwa-512.png', $manifest['icons'][2]['src'] );
	}

	/**
	 * Featured image present but sized variants don't exist on disk
	 * (`wp_get_attachment_image_src` returns false). Caller must fall
	 * back to bundled icons rather than emit broken URLs.
	 */
	public function test_build_manifest_falls_back_when_sized_variants_missing(): void {
		$this->stub_url_helpers( 'https://example.test' );
		Functions\when( 'get_the_title' )->justReturn( 'Wedding' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/wedding/' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
		Functions\when( 'wp_get_global_styles' )->justReturn( array() );
		Functions\when( 'get_background_color' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		$manifest = PWA::build_manifest( 123 );

		$this->assertStringContainsString( 'assets/pwa/icon-192.png', $manifest['icons'][0]['src'] );
	}

	/**
	 * The `pixfete_manifest` filter receives the manifest array and the
	 * post ID, and its return value is what `build_manifest` returns.
	 */
	public function test_build_manifest_applies_pixfete_manifest_filter(): void {
		$this->stub_url_helpers( 'https://example.test' );
		Functions\when( 'get_the_title' )->justReturn( 'Wedding' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/wedding/' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_get_global_styles' )->justReturn( array() );
		Functions\when( 'get_background_color' )->justReturn( '' );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value, $post_id = null ) {
				if ( 'pixfete_manifest' === $hook ) {
					$value['name']          = 'Filtered Name';
					$value['_post_id_seen'] = $post_id;
				}
				return $value;
			}
		);

		$manifest = PWA::build_manifest( 123 );

		$this->assertSame( 'Filtered Name', $manifest['name'] );
		$this->assertSame( 123, $manifest['_post_id_seen'] );
	}
}
