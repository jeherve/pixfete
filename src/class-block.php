<?php
/**
 * Register the Gutenberg block and page template.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

defined( 'ABSPATH' ) || exit;

/**
 * Register the block, page template, and handle server-side rendering.
 */
class Block {

	/**
	 * Template slug for the full-screen event album page.
	 *
	 * @var string
	 */
	private const TEMPLATE_SLUG = 'page-event-album';

	/**
	 * Register the block and page template.
	 *
	 * @return void
	 */
	public static function register(): void {
		// 1. Register the block type from block.json metadata.
		register_block_type( PIXFETE_PLUGIN_DIR . 'build/blocks/event-album' );

		// 2. Register the full-screen page template.
		$template_content = (string) file_get_contents( PIXFETE_PLUGIN_DIR . 'templates/page-event-album.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a remote URL.
		register_block_template(
			'pixfete//' . self::TEMPLATE_SLUG,
			array(
				'title'       => esc_html__( 'Event Album (Full Screen)', 'pixfete' ),
				'description' => esc_html__( 'A minimal template for the event photo album — just the site logo and page content, no header or footer.', 'pixfete' ),
				'content'     => $template_content,
				'post_types'  => array( 'page' ),
			)
		);

		// Hook the manifest <link> emission at wp_head. Block render runs during
		// the_content (after wp_head has already fired), so we register the callback
		// here at init time and inspect the post when wp_head fires.
		add_action( 'wp_head', array( self::class, 'maybe_render_manifest_link' ), 1 );
	}

	/**
	 * Emit `<link rel="manifest">` when the current page renders an event-album block.
	 *
	 * Registered at `wp_head` (priority 1) so the manifest declaration lands
	 * in `<head>` before scripts. Cannot live in `render.php` — block render
	 * happens during `the_content`, after `wp_head` has already fired.
	 *
	 * Skipped on:
	 *   - Non-singular contexts (archives, REST previews, embeds) where
	 *     "this event" doesn't map to one post.
	 *   - Posts that don't qualify as event-album manifest targets per
	 *     {@see PWA::is_event_album_post()}. Reusing that check keeps the
	 *     `<link>` and the endpoint in lockstep: previewing a draft would
	 *     otherwise emit a manifest URL that the endpoint 404s, which
	 *     shows up in DevTools as a noisy "manifest fetch failed".
	 *   - Sites where `pixfete_serve_manifest` returns false.
	 *
	 * The static `$emitted` flag is a belt-and-suspenders guard against
	 * the callback being invoked more than once per request (e.g. a
	 * double-registration via `add_action`, or `wp_head` firing twice
	 * because a theme calls it manually). Multiple event-album blocks in
	 * the same post already collapse into one `<link>` because the action
	 * is registered once.
	 *
	 * @return void
	 */
	public static function maybe_render_manifest_link(): void {
		static $emitted = false;
		if ( $emitted ) {
			return;
		}

		if ( ! \Jeherve\Pixfete\PWA::is_manifest_enabled() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( null === $post ) {
			return;
		}

		if ( ! \Jeherve\Pixfete\PWA::is_event_album_post( $post->ID ) ) {
			return;
		}

		$manifest_url = home_url( sprintf( \Jeherve\Pixfete\PWA::MANIFEST_PATH_TEMPLATE, $post->ID ) );

		echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '" />' . "\n";

		$emitted = true;
	}
}
