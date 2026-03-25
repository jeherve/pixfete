<?php
/**
 * Register the Gutenberg block, block pattern category, and block pattern.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

/**
 * Register the block and handle server-side rendering.
 */
class Block {

	/**
	 * Block name constant.
	 *
	 * @var string
	 */
	private const BLOCK_NAME = 'event-guest-photos-sharing/event-album';

	/**
	 * Pattern category slug.
	 *
	 * @var string
	 */
	private const PATTERN_CATEGORY = 'event-guest-photos-sharing';

	/**
	 * Template slug for the full-screen event album page.
	 *
	 * @var string
	 */
	private const TEMPLATE_SLUG = 'page-event-album';

	/**
	 * Register the block, pattern category, and block pattern.
	 *
	 * @return void
	 */
	public static function register(): void {
		// 1. Register the block type from block.json metadata.
		register_block_type( EGPS_PLUGIN_DIR . 'build/blocks/event-album' );

		// 2. Register the block pattern category.
		register_block_pattern_category(
			self::PATTERN_CATEGORY,
			array(
				'label' => esc_html__( 'Event', 'event-guest-photos-sharing' ),
			)
		);

		// 3. Register the block pattern.
		$heading_text = esc_html__( 'Event Photo Album', 'event-guest-photos-sharing' );
		$consent_text = esc_html__( 'By sharing your photos, you agree that they will be visible to all event guests.', 'event-guest-photos-sharing' );

		$pattern_content = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group">'
			. '<!-- wp:heading -->'
			. '<h2 class="wp-block-heading">' . $heading_text . '</h2>'
			. '<!-- /wp:heading -->'
			. '<!-- wp:event-guest-photos-sharing/event-album -->'
			. '<div class="wp-block-event-guest-photos-sharing-event-album">'
			. '<!-- wp:paragraph -->'
			. '<p>' . $consent_text . '</p>'
			. '<!-- /wp:paragraph -->'
			. '</div>'
			. '<!-- /wp:event-guest-photos-sharing/event-album -->'
			. '</div>'
			. '<!-- /wp:group -->';

		register_block_pattern(
			self::BLOCK_NAME,
			array(
				'title'      => esc_html__( 'Event Photo Album', 'event-guest-photos-sharing' ),
				'categories' => array( self::PATTERN_CATEGORY ),
				'postTypes'  => array( 'page' ),
				'content'    => $pattern_content,
			)
		);

		// 4. Register the full-screen page template.
		$template_content = (string) file_get_contents( EGPS_PLUGIN_DIR . 'templates/page-event-album.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a remote URL.
		wp_register_block_template(
			'event-guest-photos-sharing//' . self::TEMPLATE_SLUG,
			array(
				'title'       => esc_html__( 'Event Album (Full Screen)', 'event-guest-photos-sharing' ),
				'description' => esc_html__( 'A minimal template for the event photo album — just the site logo and page content, no header or footer.', 'event-guest-photos-sharing' ),
				'content'     => $template_content,
				'post_types'  => array( 'page' ),
			)
		);
	}
}
