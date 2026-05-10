<?php
/**
 * PWA wiring: register a rewrite rule that serves the Service Worker
 * from the site origin root with `Service-Worker-Allowed: /`, so the
 * SW can claim event-album pages that live outside the plugin path.
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
 * under that path. Album pages live anywhere on the site, so we route
 * `/pixfete-sw.js` through PHP and add `Service-Worker-Allowed: /`.
 */
class PWA {

	/**
	 * Register the rewrite rule for the SW URL.
	 *
	 * Hooked on `init`. Rewrite rules need flushing the first time —
	 * the activation hook in pixfete.php takes care of that.
	 *
	 * @return void
	 */
	public static function register_rewrite(): void {
		add_rewrite_rule( '^pixfete-sw\.js$', 'index.php?pixfete_sw=1', 'top' );
	}

	/**
	 * Allow the `pixfete_sw` query var so WP keeps it on the request.
	 *
	 * @param array<int, string> $vars Existing public query vars.
	 * @return array<int, string> Vars including pixfete_sw.
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = 'pixfete_sw';
		return $vars;
	}

	/**
	 * If the current request matches `/pixfete-sw.js`, stream the JS file.
	 *
	 * Sends `Service-Worker-Allowed: /` so the registration call in the
	 * page can claim any path on the origin. Adds a short cache to keep
	 * the network tab quiet during navigation but not so long that fixes
	 * stay stuck.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		if ( ! get_query_var( 'pixfete_sw' ) ) {
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
	 * Flush rewrite rules. Called from the plugin activation hook.
	 *
	 * @return void
	 */
	public static function on_activate(): void {
		self::register_rewrite();
		flush_rewrite_rules();
	}

	/**
	 * Restore default rewrite state on deactivation.
	 *
	 * @return void
	 */
	public static function on_deactivate(): void {
		flush_rewrite_rules();
	}
}
