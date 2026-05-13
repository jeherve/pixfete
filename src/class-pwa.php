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
		 * @since 1.3.0
		 *
		 * @param bool $enabled Whether Pixfête's manifest is active.
		 */
		return (bool) apply_filters( 'pixfete_serve_manifest', true );
	}

	/**
	 * Dispatch the current request to the SW or manifest streamer.
	 *
	 * The matcher logic is delegated to {@see self::matches_sw_path()}
	 * and {@see self::matches_manifest_path()} so tests can exercise the
	 * URL parsing directly without tripping the `exit()` calls that end
	 * the response. Each route is gated by its own filter (`is_enabled`
	 * for the Service Worker, `is_manifest_enabled` for the manifest) so
	 * hosts can independently disable either side.
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
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( '' === $request_uri ) {
			return;
		}

		if ( self::is_enabled() && self::matches_sw_path( $request_uri ) ) {
			self::serve_sw();
			return;
		}

		if ( self::is_manifest_enabled() ) {
			$manifest_post_id = self::matches_manifest_path( $request_uri );
			if ( null !== $manifest_post_id ) {
				self::serve_manifest( $manifest_post_id );
				return;
			}
		}
	}

	/**
	 * Stream the built Service Worker file to the client.
	 *
	 * Extracted from `maybe_serve()` so the dispatcher reads as a flat
	 * list of "match these URLs". Behaviour is identical to the previous
	 * inline path.
	 *
	 * @return void
	 */
	private static function serve_sw(): void {
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
	 * Stream the JSON-encoded manifest for a given event post.
	 *
	 * 404s on a missing post so we never emit a manifest pointing at
	 * `/?p=<deleted>`. Cache-Control matches the SW: browsers revalidate
	 * on every fetch so a relaunched album (or a host changing icons)
	 * doesn't get stuck behind a stale manifest.
	 *
	 * The endpoint is publicly enumerable (`/pixfete-1.webmanifest`,
	 * `/pixfete-2.webmanifest`, …), so we restrict it to *published* posts
	 * that actually carry the `pixfete/event-album` block. Otherwise an
	 * unauthenticated probe could harvest titles/permalinks of drafts,
	 * private posts, or unrelated content via the manifest body.
	 *
	 * @param int $post_id Event-album post ID parsed from the URL.
	 * @return void
	 */
	private static function serve_manifest( int $post_id ): void {
		if ( ! self::is_event_album_post( $post_id ) ) {
			status_header( 404 );
			exit;
		}

		$manifest = self::build_manifest( $post_id );
		$body     = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $body ) {
			status_header( 500 );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode returns already-escaped JSON.
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
	 * Decide whether a given post ID is a valid event-album manifest target.
	 *
	 * Two checks: the post must exist with `publish` status (drafts,
	 * private posts, and trashed posts would leak via the manifest body
	 * otherwise) and it must contain the `pixfete/event-album` block so a
	 * probe of `/pixfete-<id>.webmanifest` against an arbitrary post
	 * returns 404 instead of a nonsensical manifest for non-event content.
	 *
	 * Exposed as a public testing seam so the published-status and
	 * block-presence rules can be covered without exercising the
	 * `exit()`-terminated streaming path.
	 *
	 * @param int $post_id Candidate post ID.
	 * @return bool True when $post_id is a publicly installable event album.
	 */
	public static function is_event_album_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		return function_exists( 'has_block' ) && has_block( 'pixfete/event-album', $post );
	}

	/**
	 * Build the manifest array for a given event-album post.
	 *
	 * Pure function: takes a post ID, calls WP getters, returns the
	 * associative array that will be JSON-encoded for the response. Keeps
	 * the serving path (`maybe_serve`) trivial and lets the manifest shape
	 * be fully covered by unit tests without exercising the streaming code
	 * that ends in `exit()`.
	 *
	 * Icon resolution:
	 *   - With a featured image: emit three icon entries pointing at the
	 *     192/512 sized variants and a maskable variant that reuses the
	 *     512px source (hosts can ship a properly-padded maskable image
	 *     via the `pixfete_manifest` filter if they need one).
	 *   - Without a featured image: fall back to the bundled Pixfête icons
	 *     under `assets/pwa/`.
	 *
	 * The `pixfete_manifest` filter runs last so hosts can rewrite any
	 * field — name, icons, theme color — without forking the plugin.
	 *
	 * @param int $post_id ID of the event-album post.
	 * @return array<string, mixed> Manifest array ready for JSON encoding.
	 */
	public static function build_manifest( int $post_id ): array {
		$title       = (string) get_the_title( $post_id );
		$permalink   = (string) get_permalink( $post_id );
		$theme_color = self::resolve_theme_color();

		$manifest = array(
			'name'             => $title,
			'short_name'       => self::short_name( $title ),
			'start_url'        => $permalink,
			'scope'            => $permalink,
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'theme_color'      => $theme_color,
			'background_color' => $theme_color,
			'icons'            => self::manifest_icons( $post_id ),
		);

		/**
		 * Filters the generated manifest array before JSON encoding.
		 *
		 * Hosts can add fields (`shortcuts`, `share_target`, custom icon
		 * sets), rewrite name/colors, or replace the icons array entirely.
		 * Pixfête does not validate the result — invalid manifests will
		 * surface as browser warnings.
		 *
		 * @since 1.3.0
		 *
		 * @param array $manifest Manifest array Pixfête generated.
		 * @param int   $post_id  ID of the event-album post.
		 */
		return (array) apply_filters( 'pixfete_manifest', $manifest, $post_id );
	}

	/**
	 * Resolve the `icons` array for a manifest.
	 *
	 * @param int $post_id ID of the event-album post.
	 * @return array<int, array<string, string>> Manifest-shape icon entries.
	 */
	private static function manifest_icons( int $post_id ): array {
		$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id > 0 ) {
			$featured = self::featured_image_icons( $thumbnail_id );
			if ( ! empty( $featured ) ) {
				return $featured;
			}
		}

		return array(
			array(
				'src'     => PIXFETE_PLUGIN_URL . 'assets/pwa/icon-192.png',
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => PIXFETE_PLUGIN_URL . 'assets/pwa/icon-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => PIXFETE_PLUGIN_URL . 'assets/pwa/icon-maskable-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			),
		);
	}

	/**
	 * Resolve featured-image-derived icons or return an empty array.
	 *
	 * Returns empty (so caller falls back to bundled icons) when the
	 * sized variants don't exist on disk. This happens on attachments
	 * uploaded before the Pixfête image sizes were registered, or on
	 * sites that don't regenerate thumbnails after activation.
	 *
	 * The `type` field is derived from the attachment's mime type rather
	 * than hard-coded: Pixfête registers `pixfete-pwa-192`/`pixfete-pwa-512`
	 * via `add_image_size()`, which preserves the source format, so a
	 * JPEG/WebP/AVIF featured image yields same-format thumbnails. Sending
	 * a wrong `type` triggers browser-install-prompt warnings or causes
	 * the icon to be ignored entirely. We fall back to omitting `type`
	 * when the attachment doesn't report one — browsers can sniff from
	 * the URL/response in that case.
	 *
	 * @param int $thumbnail_id Featured-image attachment ID.
	 * @return array<int, array<string, string>> Icon entries, or [].
	 */
	private static function featured_image_icons( int $thumbnail_id ): array {
		$small = wp_get_attachment_image_src( $thumbnail_id, 'pixfete-pwa-192' );
		$large = wp_get_attachment_image_src( $thumbnail_id, 'pixfete-pwa-512' );
		if ( ! is_array( $small ) || ! is_array( $large ) ) {
			return array();
		}
		if ( empty( $small[0] ) || empty( $large[0] ) ) {
			return array();
		}

		$mime_type = (string) get_post_mime_type( $thumbnail_id );

		$small_icon = array(
			'src'     => (string) $small[0],
			'sizes'   => '192x192',
			'purpose' => 'any',
		);
		$large_icon = array(
			'src'     => (string) $large[0],
			'sizes'   => '512x512',
			'purpose' => 'any',
		);
		$maskable   = array(
			'src'     => (string) $large[0],
			'sizes'   => '512x512',
			'purpose' => 'maskable',
		);

		if ( '' !== $mime_type ) {
			$small_icon['type'] = $mime_type;
			$large_icon['type'] = $mime_type;
			$maskable['type']   = $mime_type;
		}

		return array( $small_icon, $large_icon, $maskable );
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
			return self::truncate_chars( $title, 12 );
		}
		$first = (string) $segments[0];
		if ( self::char_length( $first ) <= 12 ) {
			return $first;
		}
		return self::truncate_chars( $first, 12 );
	}

	/**
	 * Count characters in a string, preferring mbstring when available.
	 *
	 * The mbstring extension is "recommended" by WordPress but not required, and Pixfête
	 * does not declare `ext-mbstring` in its requirements. On hosts that
	 * disabled it, calling `mb_strlen()` directly would fatal at runtime
	 * and take the manifest endpoint (and any other call site) down.
	 *
	 * The fallback uses a UTF-8-aware regex so multibyte titles still
	 * count by character rather than byte — `strlen()` would over-count
	 * emoji/accented titles and trigger over-aggressive truncation.
	 *
	 * @param string $value Input string in any locale.
	 * @return int Number of characters in $value.
	 */
	private static function char_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value );
		}
		return (int) preg_match_all( '/./us', $value );
	}

	/**
	 * Truncate a string to a character (not byte) length.
	 *
	 * The mbstring fallback: see {@see self::char_length()} for why we can't
	 * assume `mb_substr()` exists. The regex fallback captures the first
	 * `$length` UTF-8 characters so multibyte titles aren't cut mid-byte.
	 *
	 * @param string $value  Input string in any locale.
	 * @param int    $length Maximum number of characters to keep.
	 * @return string Truncated string.
	 */
	private static function truncate_chars( string $value, int $length ): string {
		if ( $length <= 0 || '' === $value ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, 0, $length );
		}
		if ( preg_match( '/^(.{0,' . $length . '})/us', $value, $matches ) ) {
			return (string) $matches[1];
		}
		return $value;
	}
}
