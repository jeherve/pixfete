<?php
/**
 * Tests for the Slideshow class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Slideshow;
use PHPUnit\Framework\TestCase;

/**
 * Test the Slideshow class registration of block type and page template.
 */
class SlideshowTest extends TestCase {

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

	// ─── Block registration ───────────────────────────────────────────

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

		Functions\expect( 'register_block_template' )
			->once();

		Slideshow::register();

		$this->assertSame(
			EGPS_PLUGIN_DIR . 'build/blocks/event-slideshow',
			$captured_path,
			'register_block_type must be called with the correct block directory path.'
		);
	}

	// ─── Template registration ────────────────────────────────────────

	/**
	 * Test that register() calls register_block_template for the event slideshow template.
	 */
	public function test_register_calls_register_block_template(): void {
		$captured_id   = null;
		$captured_args = null;

		Functions\expect( 'register_block_type' )
			->once();

		Functions\expect( 'register_block_template' )
			->once()
			->withArgs(
				function ( $id, $args ) use ( &$captured_id, &$captured_args ) {
					$captured_id   = $id;
					$captured_args = $args;
					return true;
				}
			);

		Slideshow::register();

		$this->assertSame(
			'event-guest-photos-sharing//page-event-slideshow',
			$captured_id,
			'Template ID must follow the plugin-slug//template-slug format.'
		);
		$this->assertArrayHasKey( 'title', $captured_args );
		$this->assertSame(
			'Event Slideshow (Full Screen)',
			$captured_args['title'],
			'Template title must be "Event Slideshow (Full Screen)".'
		);
		$this->assertArrayHasKey( 'content', $captured_args );
		$this->assertStringContainsString(
			'wp:post-content',
			$captured_args['content'],
			'Template content must include a post-content block.'
		);
	}
}
