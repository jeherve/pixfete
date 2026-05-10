<?php
/**
 * PWA wiring: serve the Service Worker from the site origin root with
 * `Service-Worker-Allowed: /`, so the SW can claim event-album pages
 * that live outside the plugin path.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

defined( 'ABSPATH' ) || exit;

/**
 * Stream the built SW JS file from a stable origin-root URL.
 *
 * Why origin-root: a Service Worker registered from
 * `/wp-content/plugins/pixfete/build/sw.js` would only control pages
 * under that path. Album pages live anywhere on the site, so we
 * intercept `GET /pixfete-sw.js` directly in `template_redirect` and
 * stream the built file with `Service-Worker-Allowed: /`.
 *
 * The earlier rewrite-rule approach has been replaced with a
 * REQUEST_URI check because rewrite rules only flush on plugin
 * activation — users upgrading from an earlier version would never
 * see the rule until they manually deactivated and reactivated.
 */
class PWA {

	/**
	 * Path that triggers the SW handler.
	 *
	 * Stored as a constant so the test suite can assert on the exact
	 * path without duplicating string literals.
	 *
	 * @var string
	 */
	public const SW_PATH = '/pixfete-sw.js';

	/**
	 * If the current request is for the SW URL, stream the built JS file.
	 *
	 * Sends `Service-Worker-Allowed: /` so the registration call in the
	 * page can claim any path on the origin. The Cache-Control header
	 * keeps the network tab quiet during navigation but expires fast
	 * enough that bug-fix releases reach guests within minutes.
	 *
	 * The matcher logic is delegated to {@see self::matches_sw_path()}
	 * so tests can exercise it directly without tripping the `exit()`
	 * call that ends the response.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( ! self::matches_sw_path( $request_uri ) ) {
			return;
		}

		$path = PIXFETE_PLUGIN_DIR . 'build/sw.js';
		if ( ! file_exists( $path ) ) {
			status_header( 404 );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'Cache-Control: max-age=300, must-revalidate' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a local plugin asset, no remote IO.
		readfile( $path );
		exit;
	}

	/**
	 * Decide whether a request URI targets the Service Worker path.
	 *
	 * Exposed as a public testing seam: `maybe_serve()` calls `exit()`
	 * which can't be cleanly intercepted in PHPUnit, so unit tests
	 * cover the matcher in isolation. The matcher strips the query
	 * string before comparing because clients (and `?ver=` cache
	 * busters) sometimes append one.
	 *
	 * The expected path is derived from `home_url( SW_PATH )` rather
	 * than the bare constant so subdirectory installs (where the home
	 * URL carries a path prefix like `/blog/`) still match.
	 *
	 * @param string $request_uri Raw value of `$_SERVER['REQUEST_URI']`,
	 *                            already unslashed and sanitized by the
	 *                            caller (or empty when unavailable).
	 * @return bool True when the path component equals the SW URL path.
	 */
	public static function matches_sw_path( string $request_uri ): bool {
		if ( '' === $request_uri ) {
			return false;
		}

		$parts = wp_parse_url( $request_uri );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '';

		$expected_parts = wp_parse_url( home_url( self::SW_PATH ) );
		$expected_path  = is_array( $expected_parts ) && isset( $expected_parts['path'] )
			? (string) $expected_parts['path']
			: self::SW_PATH;

		return $expected_path === $path;
	}
}
