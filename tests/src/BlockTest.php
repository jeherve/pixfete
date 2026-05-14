<?php
/**
 * Tests for the Block class.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Pixfete\Block;
use PHPUnit\Framework\TestCase;

/**
 * Test the Block class registration of block type and page template.
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

		Functions\expect( 'register_block_template' )
			->once();

		Block::register();

		$this->assertSame(
			PIXFETE_PLUGIN_DIR . 'build/blocks/event-album',
			$captured_path,
			'register_block_type must be called with the correct block directory path.'
		);
	}

	// ─── §6d: Template registration ─────────────────────────────────

	/**
	 * Test that register() calls register_block_template for the event album template.
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

		Block::register();

		$this->assertSame(
			'pixfete//page-event-album',
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

	// ─── Manifest <link> emission ────────────────────────────────────

	/**
	 * Manifest `<link>` is suppressed when the current post is not a
	 * legitimate event-album manifest target — e.g. a draft preview of an
	 * event-album page. Aligning with {@see PWA::is_event_album_post()}
	 * keeps the `<link>` and the endpoint in lockstep, avoiding a noisy
	 * 404 fetch in DevTools whenever a draft is previewed.
	 */
	public function test_manifest_link_is_skipped_for_draft_preview(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 42 ) );
		// Draft preview: post exists with the block but isn't published, so
		// `is_event_album_post()` returns false and the <link> should be
		// suppressed. We stub the underlying calls to keep the gate honest.
		Functions\when( 'has_block' )->alias(
			static function ( string $block_name, $post = null ): bool {
				unset( $post );
				return 'pixfete/event-album' === $block_name;
			}
		);
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'          => 42,
				'post_status' => 'draft',
			)
		);

		ob_start();
		Block::maybe_render_manifest_link();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'rel="manifest"', $output );
	}
}
