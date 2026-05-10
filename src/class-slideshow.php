<?php
/**
 * Slideshow block registration.
 *
 * Registers the event-slideshow block and its associated full-screen
 * page template. The slideshow block is designed for projecting event
 * photos onto a big screen — it displays one photo at a time with
 * crossfade transitions, auto-advancing through submissions.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the event-slideshow block type and the
 * accompanying full-screen page template.
 *
 * This class mirrors the Block class but targets the slideshow experience:
 * a distraction-free, full-screen view with no site header or footer,
 * intended for projecting guest photos during a live event.
 */
class Slideshow {

	/**
	 * Template slug for the full-screen event slideshow page.
	 *
	 * @var string
	 */
	private const TEMPLATE_SLUG = 'page-event-slideshow';

	/**
	 * Register the slideshow block type and its full-screen page template.
	 *
	 * Called on the `init` hook. Reads the template HTML from disk so that
	 * the template content is always in sync with the file on the filesystem
	 * rather than hard-coded in PHP.
	 *
	 * @return void
	 */
	public static function register(): void {
		// 1. Register the block type from block.json metadata.
		register_block_type( EGPS_PLUGIN_DIR . 'build/blocks/event-slideshow' );

		// 2. Register the full-screen page template.
		$template_content = (string) file_get_contents( EGPS_PLUGIN_DIR . 'templates/page-event-slideshow.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a remote URL.
		register_block_template(
			'pixfete//' . self::TEMPLATE_SLUG,
			array(
				'title'       => esc_html__( 'Live Photo Wall (Full Screen)', 'pixfete' ),
				'description' => esc_html__( 'A minimal full-screen template for projecting event photos. No header or footer — just the photo wall.', 'pixfete' ),
				'content'     => $template_content,
				'post_types'  => array( 'page' ),
			)
		);
	}
}
