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

		$types = Upload::get_allowed_mime_types();

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

		$types = Upload::get_allowed_mime_types();

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

		$types = Upload::get_allowed_mime_types();

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

		$this->assertFalse( Upload::is_valid_image_type( 'image/gif' ) );
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

		$this->assertTrue( Upload::is_valid_image_type( 'image/jpeg' ) );
	}

	// ─── §3b: Attachment creation with guest metadata ─────────────────

	/**
	 * Helper: build guest data for tests.
	 *
	 * @return array{guest_name: string, table_name: string, guest_id: string}
	 */
	private function make_guest_data(): array {
		return array(
			'guest_name' => 'Alice',
			'table_name' => 'Table 5',
			'guest_id'   => 'abc123def456',
		);
	}

	/**
	 * Helper: stub the common WP functions needed by create_attachment() tests.
	 */
	private function stub_common_create_attachment_functions(): void {
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $str ) {
				return trim( strip_tags( $str ) );
			}
		);
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $name ) {
				return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $v ) {
				return $v instanceof \WP_Error;
			}
		);
	}

	/**
	 * Test that create_attachment() calls wp_insert_attachment with correct post_parent.
	 */
	public function test_create_attachment_calls_wp_insert_attachment_with_correct_parent(): void {
		$page_id       = 42;
		$attachment_id = 100;

		$this->stub_common_create_attachment_functions();
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$captured_args = null;
		Functions\expect( 'wp_insert_attachment' )
			->once()
			->withArgs(
				function ( $args, $file ) use ( &$captured_args ) {
					$captured_args = $args;
					return true;
				}
			)
			->andReturn( $attachment_id );

		Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			$page_id,
			$this->make_guest_data()
		);

		$this->assertSame( $page_id, $captured_args['post_parent'] );
		$this->assertSame( 'image/jpeg', $captured_args['post_mime_type'] );
		$this->assertSame( 'inherit', $captured_args['post_status'] );
	}

	/**
	 * Test that create_attachment() stores all 5 meta fields.
	 */
	public function test_create_attachment_stores_all_meta_fields(): void {
		$attachment_id = 100;
		$guest_data    = $this->make_guest_data();

		$this->stub_common_create_attachment_functions();
		Functions\when( 'wp_insert_attachment' )->justReturn( $attachment_id );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$stored_meta = array();
		Functions\expect( 'update_post_meta' )
			->atLeast()
			->times( 5 )
			->withArgs(
				function ( $post_id, $key, $value ) use ( $attachment_id, &$stored_meta ) {
					if ( $post_id === $attachment_id ) {
						$stored_meta[ $key ] = $value;
					}
					return true;
				}
			)
			->andReturn( true );

		Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			42,
			$guest_data
		);

		$this->assertArrayHasKey( '_egps_guest_name', $stored_meta );
		$this->assertArrayHasKey( '_egps_table_name', $stored_meta );
		$this->assertArrayHasKey( '_egps_guest_id', $stored_meta );
		$this->assertArrayHasKey( '_egps_uploaded_at', $stored_meta );
		$this->assertArrayHasKey( '_egps_requires_moderation', $stored_meta );
	}

	/**
	 * Test that create_attachment() fires the egps_after_photo_upload action.
	 */
	public function test_create_attachment_fires_after_photo_upload_action(): void {
		$page_id       = 42;
		$attachment_id = 100;

		$this->stub_common_create_attachment_functions();
		Functions\when( 'wp_insert_attachment' )->justReturn( $attachment_id );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$action_fired = false;
		Functions\expect( 'do_action' )
			->once()
			->with( 'egps_after_photo_upload', $attachment_id, $page_id )
			->andReturnUsing(
				function () use ( &$action_fired ) {
					$action_fired = true;
				}
			);

		Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			$page_id,
			$this->make_guest_data()
		);

		$this->assertTrue( $action_fired, 'egps_after_photo_upload action must be fired.' );
	}

	/**
	 * Test that create_attachment() applies the egps_photo_requires_moderation filter.
	 */
	public function test_create_attachment_applies_moderation_filter(): void {
		$attachment_id = 100;

		$this->stub_common_create_attachment_functions();
		Functions\when( 'wp_insert_attachment' )->justReturn( $attachment_id );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'egps_photo_requires_moderation', false, $attachment_id, 42 )
			->andReturn( true );

		$stored_meta = array();
		Functions\expect( 'update_post_meta' )
			->atLeast()
			->times( 1 )
			->withArgs(
				function ( $post_id, $key, $value ) use ( $attachment_id, &$stored_meta ) {
					if ( $post_id === $attachment_id ) {
						$stored_meta[ $key ] = $value;
					}
					return true;
				}
			)
			->andReturn( true );

		Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			42,
			$this->make_guest_data()
		);

		$this->assertTrue( $stored_meta['_egps_requires_moderation'] );
	}

	/**
	 * Test that create_attachment() returns the attachment ID.
	 */
	public function test_create_attachment_returns_attachment_id(): void {
		$attachment_id = 100;

		$this->stub_common_create_attachment_functions();
		Functions\when( 'wp_insert_attachment' )->justReturn( $attachment_id );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$result = Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			42,
			$this->make_guest_data()
		);

		$this->assertSame( $attachment_id, $result );
	}

	/**
	 * Test that create_attachment() sanitizes guest_name and table_name with sanitize_text_field().
	 */
	public function test_create_attachment_sanitizes_guest_data(): void {
		$attachment_id = 100;
		$guest_data    = array(
			'guest_name' => '<script>Alice</script>',
			'table_name' => '<b>Table 5</b>',
			'guest_id'   => 'abc123',
		);

		$this->stub_common_create_attachment_functions();
		Functions\when( 'wp_insert_attachment' )->justReturn( $attachment_id );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$stored_meta = array();
		Functions\expect( 'update_post_meta' )
			->atLeast()
			->times( 1 )
			->withArgs(
				function ( $post_id, $key, $value ) use ( $attachment_id, &$stored_meta ) {
					if ( $post_id === $attachment_id ) {
						$stored_meta[ $key ] = $value;
					}
					return true;
				}
			)
			->andReturn( true );

		Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			42,
			$guest_data
		);

		// sanitize_text_field strips tags.
		$this->assertSame( 'Alice', $stored_meta['_egps_guest_name'] );
		$this->assertSame( 'Table 5', $stored_meta['_egps_table_name'] );
	}

	/**
	 * Test that create_attachment() applies sanitize_key() to guest_id before storage.
	 */
	public function test_create_attachment_sanitizes_guest_id(): void {
		$attachment_id = 100;
		$guest_data    = array(
			'guest_name' => 'Alice',
			'table_name' => 'Table 5',
			'guest_id'   => 'ABC-123 XYZ!',
		);

		$this->stub_common_create_attachment_functions();
		Functions\when( 'wp_insert_attachment' )->justReturn( $attachment_id );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$stored_meta = array();
		Functions\expect( 'update_post_meta' )
			->atLeast()
			->times( 1 )
			->withArgs(
				function ( $post_id, $key, $value ) use ( $attachment_id, &$stored_meta ) {
					if ( $post_id === $attachment_id ) {
						$stored_meta[ $key ] = $value;
					}
					return true;
				}
			)
			->andReturn( true );

		Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			42,
			$guest_data
		);

		// sanitize_key lowercases and strips non-alphanumeric chars (except _ and -).
		$this->assertSame( 'abc-123xyz', $stored_meta['_egps_guest_id'] );
	}

	/**
	 * Test that create_attachment() returns 0 when wp_insert_attachment() returns a WP_Error.
	 */
	public function test_create_attachment_returns_zero_on_wp_error(): void {
		$this->stub_common_create_attachment_functions();

		Functions\when( 'wp_insert_attachment' )->justReturn( new \WP_Error( 'upload_error', 'Failed.' ) );

		$result = Upload::create_attachment(
			'/tmp/test.jpg',
			'test.jpg',
			'image/jpeg',
			42,
			$this->make_guest_data()
		);

		$this->assertSame( 0, $result );
	}
}
