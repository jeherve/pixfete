<?php
/**
 * PWA wiring: serve the Service Worker from the site's home URL so the
 * SW scope cleanly matches both root and subdirectory installs (including
 * subdirectory multisite, where each subsite gets its own SW under its
 * own scope without colliding with sibling sites on the same origin).
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

defined( 'ABSPATH' ) || exit;

/**
 * Stream the built SW JS file from the site's home URL path.
 *
 * Why home_url-relative: a Service Worker registered from
 * `/wp-content/plugins/pixfete/build/sw.js` would only control pages
 * under that path. Album pages live anywhere under the WordPress home
 * URL, so we intercept `GET <home>/pixfete-sw.js` in `template_redirect`
 * and stream the built file.
 *
 * Why REQUEST_URI matching (rather than a rewrite rule): rewrite rules
 * only flush on plugin (re)activation, so users upgrading from an
 * earlier version would never see the rule until they manually toggled
 * the plugin. A path check has no activation prerequisite.
 *
 * Plugins that don't want Pixfête to manage the Service Worker (e.g.
 * sites that already register a SW via Super PWA, OneSignal, Jetpack
 * Boost, or a host-managed offline plugin) can disable Pixfête's SW
 * entirely via the `pixfete_serve_service_worker` filter.
 */
class PWA {

	/**
	 * Path component appended to the site home URL to reach the SW.
	 *
	 * Stored as a constant so the test suite (and render.php callers)
	 * can assert on the exact path without duplicating string literals.
	 *
	 * @var string
	 */
	public const SW_PATH = '/pixfete-sw.js';

	/**
	 * Whether Pixfête should manage a Service Worker on this site.
	 *
	 * Wrapped behind the `pixfete_serve_service_worker` filter so site
	 * owners running a competing SW plugin can step Pixfête aside
	 * without forking the plugin. Default true — the PWA flow is
	 * built into the upload pipeline and is on by default.
	 *
	 * @return bool True when Pixfête should serve and register a SW.
	 */
	public static function is_enabled(): bool {
		/**
		 * Filters whether Pixfête manages its own Service Worker.
		 *
		 * Return false to let another PWA/SW plugin own the origin scope.
		 * When disabled, Pixfête neither serves `/pixfete-sw.js` nor asks
		 * the frontend to register a Service Worker. Uploads still queue
		 * to IndexedDB and drain via the in-page loop, just without
		 * Background Sync recovery after tab close.
		 *
		 * @since 1.3.1
		 *
		 * @param bool $enabled Whether Pixfête's SW is active.
		 */
		return (bool) apply_filters( 'pixfete_serve_service_worker', true );
	}

	/**
	 * If the current request is for the SW URL, stream the built JS file.
	 *
	 * The matcher logic is delegated to {@see self::matches_sw_path()}
	 * so tests can exercise it directly without tripping the `exit()`
	 * call that ends the response.
	 *
	 * `Service-Worker-Allowed` is sent with the SW path's directory so
	 * the registration can claim every page under the WordPress home
	 * URL — including subdirectory installs, where the home path is
	 * something like `/blog/` and the default scope is what we want
	 * anyway. `Cache-Control: no-cache` forces the browser to revalidate
	 * on every fetch, matching the way browsers already treat SW
	 * responses during their own update checks.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

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
		header( 'Service-Worker-Allowed: ' . self::sw_scope() );
		header( 'Cache-Control: no-cache, must-revalidate' );
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

		return self::sw_path() === $path;
	}

	/**
	 * The full path component of the Service Worker URL on this site.
	 *
	 * Equal to the path part of `home_url( SW_PATH )`, e.g.
	 * `/pixfete-sw.js` on a root install or `/blog/pixfete-sw.js` on a
	 * subdirectory install. Exposed so render.php can keep its scope
	 * computation in sync with the matcher without re-implementing the
	 * URL parse.
	 *
	 * @return string Absolute path including the SW filename.
	 */
	public static function sw_path(): string {
		$expected_parts = wp_parse_url( home_url( self::SW_PATH ) );

		return is_array( $expected_parts ) && isset( $expected_parts['path'] )
			? (string) $expected_parts['path']
			: self::SW_PATH;
	}

	/**
	 * Scope that the page-side `register()` call should request.
	 *
	 * Equal to the home URL path component (with trailing slash), e.g.
	 * `/` on a root install or `/blog/` on a subdirectory install — so a
	 * subdirectory multisite hosts one SW per subsite without sibling
	 * sites trampling each other on the same origin.
	 *
	 * @return string Trailing-slash-terminated scope string.
	 */
	public static function sw_scope(): string {
		$parts = wp_parse_url( home_url( '/' ) );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		return '' === $path ? '/' : rtrim( $path, '/' ) . '/';
	}
}
