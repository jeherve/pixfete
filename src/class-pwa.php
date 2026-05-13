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
	 * URL path template for per-event Web App Manifests.
	 *
	 * `%d` is replaced with the post ID. Stored as a `sprintf` template
	 * (not just a prefix + extension) so tests and callers share one
	 * authoritative pattern instead of duplicating string-building logic.
	 *
	 * @var string
	 */
	public const MANIFEST_PATH_TEMPLATE = '/pixfete-%d.webmanifest';

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
		 * @since 1.3.0
		 *
		 * @param bool $enabled Whether Pixfête's SW is active.
		 */
		return (bool) apply_filters( 'pixfete_serve_service_worker', true );
	}

	/**
	 * Whether Pixfête should serve a Web App Manifest on this site.
	 *
	 * Independent from `is_enabled()` (the Service Worker switch) so a host
	 * can run, say, Pixfête's SW with a custom-branded manifest from another
	 * plugin, or vice versa. Default true — the install flow is part of the
	 * Pixfête experience and ships enabled.
	 *
	 * @return bool True when Pixfête should serve `/pixfete-<id>.webmanifest`
	 *              and emit `<link rel="manifest">` on event pages.
	 */
	public static function is_manifest_enabled(): bool {
		/**
		 * Filters whether Pixfête manages its own Web App Manifest.
		 *
		 * Return false to suppress the manifest URL and the `<link>` tag.
		 * The Service Worker is unaffected and can still run.
		 *
		 * @since 1.4.0
		 *
		 * @param bool $enabled Whether Pixfête's manifest is active.
		 */
		return (bool) apply_filters( 'pixfete_serve_manifest', true );
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

	/**
	 * Absolute path component for a given event's manifest URL.
	 *
	 * Equal to the path part of `home_url( sprintf( MANIFEST_PATH_TEMPLATE, $post_id ) )`
	 * so subdirectory installs (`/blog/pixfete-123.webmanifest`) and root installs
	 * (`/pixfete-123.webmanifest`) both produce the right value with one branch.
	 *
	 * @param int $post_id ID of the event-album post.
	 * @return string Absolute path including the manifest filename.
	 */
	public static function manifest_path( int $post_id ): string {
		$parts = wp_parse_url( home_url( sprintf( self::MANIFEST_PATH_TEMPLATE, $post_id ) ) );
		return is_array( $parts ) && isset( $parts['path'] )
			? (string) $parts['path']
			: sprintf( self::MANIFEST_PATH_TEMPLATE, $post_id );
	}

	/**
	 * Decide whether a request URI targets an event's manifest URL.
	 *
	 * Returns the parsed post ID on a match so the dispatcher in
	 * `maybe_serve()` doesn't have to re-parse the URL. Returns null
	 * when the path doesn't match, when the ID is non-numeric, or when
	 * the ID is zero/negative — we never want to feed junk into
	 * `get_post()` downstream.
	 *
	 * Exposed as a public testing seam: `maybe_serve()` calls `exit()`,
	 * so unit tests cover the matcher directly. The query string is
	 * stripped before comparing (clients may append `?ver=` cache busters).
	 *
	 * @param string $request_uri Raw value of `$_SERVER['REQUEST_URI']`,
	 *                            already unslashed and sanitized by the caller.
	 * @return int|null Post ID on match, null otherwise.
	 */
	public static function matches_manifest_path( string $request_uri ): ?int {
		if ( '' === $request_uri ) {
			return null;
		}

		$parts = wp_parse_url( $request_uri );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $path ) {
			return null;
		}

		$home_parts = wp_parse_url( home_url( '/' ) );
		$home_path  = is_array( $home_parts ) && isset( $home_parts['path'] ) ? rtrim( (string) $home_parts['path'], '/' ) : '';
		$expected   = $home_path . '/pixfete-';

		if ( ! str_starts_with( $path, $expected ) ) {
			return null;
		}

		$tail = substr( $path, strlen( $expected ) );
		if ( ! str_ends_with( $tail, '.webmanifest' ) ) {
			return null;
		}

		$id_part = substr( $tail, 0, -strlen( '.webmanifest' ) );
		if ( '' === $id_part || ! ctype_digit( $id_part ) ) {
			return null;
		}

		$post_id = (int) $id_part;
		return $post_id > 0 ? $post_id : null;
	}

	/**
	 * Resolve the theme's background color for use in the manifest.
	 *
	 * Both `theme_color` (OS chrome) and `background_color` (splash) live
	 * downstream of this; we use one source so the install dialog and
	 * launched-app splash visually match the host's own site.
	 *
	 * Resolution order:
	 *   1. Block-theme global styles (`wp_get_global_styles()` →
	 *      `color.background`). Modern themes set this in `theme.json`.
	 *   2. Classic-theme `get_background_color()` (returns hex without `#`,
	 *      empty string when no custom color is set).
	 *   3. Hardcoded `#ffffff` — neutral default for any theme that
	 *      doesn't expose a background color anywhere.
	 *
	 * Output is always `#RRGGBB`. Shorthand hex from theme.json is expanded
	 * so manifest validators don't reject it.
	 *
	 * @return string Lowercased hex color including the leading `#`.
	 */
	public static function resolve_theme_color(): string {
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$styles = wp_get_global_styles();
			if ( is_array( $styles ) && isset( $styles['color']['background'] ) ) {
				$candidate = self::normalize_hex( (string) $styles['color']['background'] );
				if ( '' !== $candidate ) {
					return $candidate;
				}
			}
		}

		if ( function_exists( 'get_background_color' ) ) {
			$classic = (string) get_background_color();
			if ( '' !== $classic ) {
				$candidate = self::normalize_hex( '#' . ltrim( $classic, '#' ) );
				if ( '' !== $candidate ) {
					return $candidate;
				}
			}
		}

		return '#ffffff';
	}

	/**
	 * Normalize a hex color string to `#RRGGBB` form (lowercase).
	 *
	 * Accepts `#abc`, `#abcdef`, `abc`, `abcdef`. Returns an empty string
	 * when the input isn't a valid hex color so callers can fall through
	 * to the next resolution layer.
	 *
	 * @param string $value Raw color string from a theme source.
	 * @return string Normalized hex, or empty string when unparseable.
	 */
	private static function normalize_hex( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( '#' !== $value[0] ) {
			$value = '#' . $value;
		}
		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m ) ) {
			return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Smart-truncate a post title for the manifest's `short_name` field.
	 *
	 * The home-screen label only renders ~12 chars before the OS truncates,
	 * so we pre-truncate intelligently rather than letting Android cut at
	 * an awkward spot. We split on the joiners hosts most commonly use to
	 * combine names ("&", em-dash, plain whitespace), keep the first
	 * non-empty segment, and hard-truncate that segment to 12 chars if it's
	 * still too long. Empty input passes through untouched.
	 *
	 * Why a separate method (not inline): the rule is fiddly enough that
	 * we want one place to test exhaustively and one place for hosts to
	 * read if they're wondering why their app label looks the way it does.
	 *
	 * @param string $title Post title in any locale.
	 * @return string A label suitable for the manifest `short_name` field.
	 */
	public static function short_name( string $title ): string {
		if ( '' === $title ) {
			return '';
		}
		$segments = preg_split( '/(\s|&|—)+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $segments ) || empty( $segments ) ) {
			return mb_substr( $title, 0, 12 );
		}
		$first = (string) $segments[0];
		if ( mb_strlen( $first ) <= 12 ) {
			return $first;
		}
		return mb_substr( $first, 0, 12 );
	}
}
