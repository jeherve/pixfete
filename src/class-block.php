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
			. '<!-- wp:paragraph -->'
			. '<p>' . $consent_text . '</p>'
			. '<!-- /wp:paragraph -->'
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
	}
}
