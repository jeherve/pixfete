<?php
/**
 * Tests for the Block class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Block;
use PHPUnit\Framework\TestCase;

/**
 * Test the Block class registration of block type, pattern category, and pattern.
 */
class BlockTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub i18n functions.
		Functions\when( 'esc_html__' )->alias(
			// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Matches WordPress function signature.
			function ( string $text, string $domain = 'default' ): string {
				return $text;
			}
		);
	}

	/**
	 * Tear down Brain Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── §6a: Block registration ─────────────────────────────────────

	/**
	 * Test that register() calls register_block_type with the correct path.
	 */
	public function test_register_calls_register_block_type_with_correct_path(): void {
		$captured_path = null;

		Functions\expect( 'register_block_type' )
			->once()
			->withArgs(
				function ( $path ) use ( &$captured_path ) {
					$captured_path = $path;
					return true;
				}
			);

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once();

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertSame(
			EGPS_PLUGIN_DIR . 'build/blocks/event-album',
			$captured_path,
			'register_block_type must be called with the correct block directory path.'
		);
	}

	/**
	 * Test that register() calls register_block_pattern_category with the correct slug and label.
	 */
	public function test_register_calls_register_block_pattern_category(): void {
		$captured_slug = null;
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once()
			->withArgs(
				function ( $slug, $args ) use ( &$captured_slug, &$captured_args ) {
					$captured_slug = $slug;
					$captured_args = $args;
					return true;
				}
			);

		Functions\expect( 'register_block_pattern' )
			->once();

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertSame(
			'event-guest-photos-sharing',
			$captured_slug,
			'Pattern category slug must be "event-guest-photos-sharing".'
		);
		$this->assertArrayHasKey( 'label', $captured_args );
		$this->assertSame(
			'Event',
			$captured_args['label'],
			'Pattern category label must be "Event".'
		);
	}

	/**
	 * Test that register() calls register_block_pattern with the correct name and args.
	 */
	public function test_register_calls_register_block_pattern_with_correct_args(): void {
		$captured_name = null;
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once()
			->withArgs(
				function ( $name, $args ) use ( &$captured_name, &$captured_args ) {
					$captured_name = $name;
					$captured_args = $args;
					return true;
				}
			);

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertSame(
			'event-guest-photos-sharing/event-album',
			$captured_name,
			'Block pattern name must be "event-guest-photos-sharing/event-album".'
		);
	}

	/**
	 * Test that block pattern content contains the block name.
	 */
	public function test_pattern_content_contains_block_name(): void {
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once()
			->withArgs(
				function ( $name, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			);

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertArrayHasKey( 'content', $captured_args );
		$this->assertStringContainsString(
			'event-guest-photos-sharing/event-album',
			$captured_args['content'],
			'Pattern content must contain the block name.'
		);
	}

	/**
	 * Test that block pattern has postTypes set to page only.
	 */
	public function test_pattern_has_post_types_page(): void {
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once()
			->withArgs(
				function ( $name, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			);

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertArrayHasKey( 'postTypes', $captured_args );
		$this->assertSame(
			array( 'page' ),
			$captured_args['postTypes'],
			'Pattern postTypes must be ["page"].'
		);
	}

	/**
	 * Test that block pattern has the correct categories.
	 */
	public function test_pattern_has_correct_categories(): void {
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once()
			->withArgs(
				function ( $name, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			);

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertArrayHasKey( 'categories', $captured_args );
		$this->assertSame(
			array( 'event-guest-photos-sharing' ),
			$captured_args['categories'],
			'Pattern must use the event-guest-photos-sharing category.'
		);
	}

	/**
	 * Test that block pattern has a title.
	 */
	public function test_pattern_has_title(): void {
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once()
			->withArgs(
				function ( $name, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			);

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertArrayHasKey( 'title', $captured_args );
		$this->assertNotEmpty( $captured_args['title'], 'Pattern must have a non-empty title.' );
	}

	/**
	 * Test that the pattern content does not contain a password attribute.
	 */
	public function test_pattern_content_does_not_contain_password(): void {
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once()
			->withArgs(
				function ( $name, $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			);

		Functions\expect( 'wp_register_block_template' )
			->once();

		Block::register();

		$this->assertStringNotContainsString(
			'"password"',
			$captured_args['content'],
			'Pattern content must not include a password attribute.'
		);
	}

	// ─── §6d: Template registration ─────────────────────────────────

	/**
	 * Test that register() calls wp_register_block_template for the event album template.
	 */
	public function test_register_calls_wp_register_block_template(): void {
		$captured_id   = null;
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_pattern_category' )
			->once();

		Functions\expect( 'register_block_pattern' )
			->once();

		Functions\expect( 'wp_register_block_template' )
			->once()
			->withArgs(
				function ( $id, $args ) use ( &$captured_id, &$captured_args ) {
					$captured_id   = $id;
					$captured_args = $args;
					return true;
				}
			);

		Block::register();

		$this->assertSame(
			'event-guest-photos-sharing//page-event-album',
			$captured_id,
			'Template ID must follow the plugin-slug//template-slug format.'
		);
		$this->assertArrayHasKey( 'title', $captured_args );
		$this->assertSame(
			'Event Album (Full Screen)',
			$captured_args['title'],
			'Template title must be "Event Album (Full Screen)".'
		);
		$this->assertArrayHasKey( 'content', $captured_args );
		$this->assertStringContainsString(
			'wp:post-content',
			$captured_args['content'],
			'Template content must include a post-content block.'
		);
		$this->assertStringContainsString(
			'wp:site-logo',
			$captured_args['content'],
			'Template content must include a site-logo block.'
		);
	}
}
