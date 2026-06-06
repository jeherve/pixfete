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
	}
}
