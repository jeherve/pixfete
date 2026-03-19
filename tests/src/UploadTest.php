<?php
/**
 * Tests for the Upload class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Upload;
use PHPUnit\Framework\TestCase;

/**
 * Test the Upload class methods for photo upload validation and attachment creation.
 */
class UploadTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── §3a: MIME type validation and HEIC detection ──────────────────

	/**
	 * Test that get_allowed_mime_types() includes jpeg, png, webp.
	 */
	public function test_get_allowed_mime_types_includes_base_types(): void {
		// Server does not support HEIC.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			)
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$upload = new Upload();
		$types  = $upload->get_allowed_mime_types();

		$this->assertContains( 'image/jpeg', $types );
		$this->assertContains( 'image/png', $types );
		$this->assertContains( 'image/webp', $types );
	}

	/**
	 * Test that get_allowed_mime_types() excludes heic/heif when server doesn't support them.
	 */
	public function test_get_allowed_mime_types_excludes_heic_when_unsupported(): void {
		// Transient not set, server check returns false.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			)
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$upload = new Upload();
		$types  = $upload->get_allowed_mime_types();

		$this->assertNotContains( 'image/heic', $types );
		$this->assertNotContains( 'image/heif', $types );
	}

	/**
	 * Test that get_allowed_mime_types() includes heic/heif when server supports them (transient returns 'supported').
	 */
	public function test_get_allowed_mime_types_includes_heic_when_supported(): void {
		// Transient returns 'supported'.
		Functions\when( 'get_transient' )->justReturn( 'supported' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$upload = new Upload();
		$types  = $upload->get_allowed_mime_types();

		$this->assertContains( 'image/heic', $types );
		$this->assertContains( 'image/heif', $types );
	}

	/**
	 * Test that is_valid_image_type() rejects image/gif.
	 */
	public function test_is_valid_image_type_rejects_gif(): void {
		// Server does not support HEIC.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			)
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$upload = new Upload();
		$this->assertFalse( $upload->is_valid_image_type( 'image/gif' ) );
	}

	/**
	 * Test that is_valid_image_type() accepts image/jpeg.
	 */
	public function test_is_valid_image_type_accepts_jpeg(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			)
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$upload = new Upload();
		$this->assertTrue( $upload->is_valid_image_type( 'image/jpeg' ) );
	}
}
