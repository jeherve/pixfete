<?php
/**
 * Tests for the Archive class.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Pixfete\Archive;
use PHPUnit\Framework\TestCase;

/**
 * Test archive ZIP generation and cron lifecycle.
 */
class ArchiveTest extends TestCase {

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

	/**
	 * Test that get_archives returns the full option array.
	 */
	public function test_get_archives_returns_option(): void {
		$data = array(
			42 => array(
				'status'    => 'complete',
				'file_path' => '/var/www/uploads/egps-archives/egps-archive-42-abc123.zip',
				'url'       => 'https://example.com/uploads/egps-archives/egps-archive-42-abc123.zip',
				'token'     => 'abc123',
			),
		);
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( $data );

		$this->assertSame( $data, Archive::get_archives() );
	}

	/**
	 * Test that get_archive returns a single entry by page ID.
	 */
	public function test_get_archive_returns_single_entry(): void {
		$entry = array(
			'status' => 'complete',
			'token'  => 'abc123',
		);
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array( 42 => $entry ) );

		$this->assertSame( $entry, Archive::get_archive( 42 ) );
	}

	/**
	 * Test that get_archive returns null for a missing page ID.
	 */
	public function test_get_archive_returns_null_for_missing(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array() );

		$this->assertNull( Archive::get_archive( 99 ) );
	}

	/**
	 * Test that update_archive merges data into a single entry.
	 */
	public function test_update_archive_merges_data(): void {
		$existing = array(
			42 => array(
				'status' => 'pending',
				'token'  => 'abc123',
			),
		);

		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( $existing );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return $name === 'egps_zip_archives'
						&& $value[42]['status'] === 'generating'
						&& $value[42]['token'] === 'abc123';
				}
			)
			->andReturn( true );

		Archive::update_archive( 42, array( 'status' => 'generating' ) );

		// Mockery enforces the ->once()->withArgs() expectations above; this confirms we reached here.
		$this->assertTrue( true );
	}

	/**
	 * Test that update_archive creates a new entry when none exists.
	 */
	public function test_update_archive_creates_new_entry(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return $name === 'egps_zip_archives'
						&& $value[42]['status'] === 'pending';
				}
			)
			->andReturn( true );

		Archive::update_archive( 42, array( 'status' => 'pending' ) );

		// Mockery enforces the ->once()->withArgs() expectations above; this confirms we reached here.
		$this->assertTrue( true );
	}

	/**
	 * Test that schedule_cron registers the daily event when not already scheduled.
	 */
	public function test_schedule_cron_registers_daily_event(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( 'egps_daily_archive_check' )
			->andReturn( false );

		Functions\expect( 'wp_schedule_event' )
			->once()
			->withArgs(
				function ( $timestamp, $recurrence, $hook ) {
					return is_int( $timestamp )
						&& $recurrence === 'daily'
						&& $hook === 'egps_daily_archive_check';
				}
			);

		Archive::schedule_cron();

		$this->assertTrue( true );
	}

	/**
	 * Test that schedule_cron does not double-schedule when already registered.
	 */
	public function test_schedule_cron_skips_when_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( 'egps_daily_archive_check' )
			->andReturn( 1742900000 );

		Functions\expect( 'wp_schedule_event' )->never();

		Archive::schedule_cron();

		$this->assertTrue( true );
	}

	/**
	 * Test that unschedule_cron clears the scheduled hook.
	 */
	public function test_unschedule_cron_clears_hook(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'egps_daily_archive_check' );
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'egps_archive_build_batch' );

		Archive::unschedule_cron();

		$this->assertTrue( true );
	}

	/**
	 * Helper to create a mock page object.
	 *
	 * @param int    $id        Page ID.
	 * @param string $post_name Post slug.
	 * @return \stdClass
	 */
	private function make_mock_page( int $id, string $post_name = 'event' ): \stdClass {
		$page            = new \stdClass();
		$page->ID        = $id;
		$page->post_name = $post_name;
		return $page;
	}

	/**
	 * Test that check_events skips pages whose event has not ended.
	 */
	public function test_check_events_skips_future_events(): void {
		$page = $this->make_mock_page( 42 );

		Functions\expect( 'get_posts' )->once()->andReturn( array( $page ) );
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'pixfete/event-album',
					'attrs'     => array( 'dateRangeEnd' => '2099-12-31' ),
				),
			)
		);

		// Should use site timezone to determine "today".
		Functions\expect( 'wp_timezone' )->once()->andReturn( new \DateTimeZone( 'UTC' ) );
		Functions\expect( 'wp_date' )->once()->andReturn( '2026-03-25' );
		Functions\expect( 'get_option' )->with( 'egps_zip_archives', array() )->andReturn( array() );

		// Should NOT schedule a batch.
		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::check_events();

		$this->assertTrue( true );
	}

	/**
	 * Test that check_events skips pages that already have an archive entry.
	 */
	public function test_check_events_skips_existing_archives(): void {
		$page = $this->make_mock_page( 42 );

		Functions\expect( 'get_posts' )->once()->andReturn( array( $page ) );
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'pixfete/event-album',
					'attrs'     => array( 'dateRangeEnd' => '2026-01-01' ),
				),
			)
		);
		Functions\expect( 'wp_timezone' )->once()->andReturn( new \DateTimeZone( 'UTC' ) );
		Functions\expect( 'wp_date' )->once()->andReturn( '2026-03-25' );

		// Already has an archive.
		Functions\expect( 'get_option' )
			->with( 'egps_zip_archives', array() )
			->andReturn( array( 42 => array( 'status' => 'complete' ) ) );

		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::check_events();

		$this->assertTrue( true );
	}

	/**
	 * Test that check_events skips pages with no attachments.
	 */
	public function test_check_events_skips_pages_with_no_attachments(): void {
		$page = $this->make_mock_page( 42 );

		Functions\expect( 'get_posts' )->once()->andReturn( array( $page ) );
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'pixfete/event-album',
					'attrs'     => array( 'dateRangeEnd' => '2026-01-01' ),
				),
			)
		);
		Functions\expect( 'wp_timezone' )->once()->andReturn( new \DateTimeZone( 'UTC' ) );
		Functions\expect( 'wp_date' )->once()->andReturn( '2026-03-25' );
		Functions\expect( 'get_option' )->with( 'egps_zip_archives', array() )->andReturn( array() );

		// No attachments — WP_Query mock returns 0 found_posts.
		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts        = array();
		$GLOBALS['egps_wp_query_mock']->found_posts  = 0;

		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::check_events();

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertTrue( true );
	}

	/**
	 * Test that check_events schedules a batch for a qualifying event.
	 */
	public function test_check_events_schedules_batch_for_ended_event(): void {
		$page = $this->make_mock_page( 42 );

		Functions\expect( 'get_posts' )->once()->andReturn( array( $page ) );
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'pixfete/event-album',
					'attrs'     => array( 'dateRangeEnd' => '2026-01-01' ),
				),
			)
		);
		Functions\expect( 'wp_timezone' )->once()->andReturn( new \DateTimeZone( 'UTC' ) );
		Functions\expect( 'wp_date' )->once()->andReturn( '2026-03-25' );

		// get_option will be called twice: once by check_events to check existing archives,
		// once by update_archive to read before writing.
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( $name === 'egps_zip_archives' ) {
					return array();
				}
				return $default;
			}
		);

		// Has attachments.
		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts        = array( (object) array( 'ID' => 100 ) );
		$GLOBALS['egps_wp_query_mock']->found_posts  = 5;

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 12, false )
			->andReturn( 'abc123def456' );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return $name === 'egps_zip_archives'
						&& $value[42]['status'] === 'pending'
						&& $value[42]['token'] === 'abc123def456';
				}
			);

		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->withArgs(
				function ( $timestamp, $hook, $args ) {
					return is_int( $timestamp )
						&& $hook === 'egps_archive_build_batch'
						&& $args === array( 42 );
				}
			);

		Archive::check_events();

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertTrue( true );
	}

	/**
	 * Test that check_events skips pages with no dateRangeEnd set.
	 */
	public function test_check_events_skips_pages_without_end_date(): void {
		$page = $this->make_mock_page( 42 );

		Functions\expect( 'get_posts' )->once()->andReturn( array( $page ) );
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'pixfete/event-album',
					'attrs'     => array(),
				),
			)
		);

		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::check_events();

		$this->assertTrue( true );
	}

	/**
	 * Test that process_batch initializes the ZIP on a pending entry.
	 *
	 * Simulates a single batch that processes all attachments (fewer than batch size),
	 * so the archive should complete in one pass.
	 */
	public function test_process_batch_completes_small_archive(): void {
		$entry = array(
			'status'      => 'pending',
			'token'       => 'abc123def456',
			'last_offset' => 0,
		);

		// get_option is called by get_archive and update_archive.
		Functions\when( 'get_option' )
			->alias(
				function ( $name, $default = false ) use ( $entry ) {
					if ( $name === 'egps_zip_archives' ) {
						return array( 42 => $entry );
					}
					return $default;
				}
			);

		// Directory setup — use a real temp directory so ZipArchive can write files.
		$base_dir    = sys_get_temp_dir() . '/egps-test-uploads-' . uniqid();
		$archive_dir = $base_dir . '/egps-archives';
		mkdir( $archive_dir, 0777, true );

		$upload_dir = array(
			'basedir' => $base_dir,
			'baseurl' => 'https://example.com/wp-content/uploads',
		);
		Functions\expect( 'wp_get_upload_dir' )->andReturn( $upload_dir );
		Functions\when( 'wp_mkdir_p' )->justReturn( true );

		/**
		 * Filter: egps_archive_directory — let it pass through.
		 * Filter: egps_archive_batch_size — let it pass through.
		 */
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);

		// Mock attachment query — 2 attachments, under batch size.
		$att1     = new \stdClass();
		$att1->ID = 100;
		$att2     = new \stdClass();
		$att2->ID = 101;

		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts        = array( $att1, $att2 );
		$GLOBALS['egps_wp_query_mock']->found_posts  = 2;

		// Mock file paths — create real temp files so ZipArchive can add them.
		$tmp1 = tempnam( sys_get_temp_dir(), 'egps' );
		$tmp2 = tempnam( sys_get_temp_dir(), 'egps' );
		file_put_contents( $tmp1, 'fake image data 1' );
		file_put_contents( $tmp2, 'fake image data 2' );

		Functions\when( 'wp_get_original_image_path' )->alias(
			function ( $id ) use ( $tmp1, $tmp2 ) {
				return $id === 100 ? $tmp1 : $tmp2;
			}
		);
		Functions\when( 'get_attached_file' )->alias(
			function ( $id ) use ( $tmp1, $tmp2 ) {
				return $id === 100 ? $tmp1 : $tmp2;
			}
		);

		// Mock basenames for the ZIP entry names.
		Functions\when( 'wp_basename' )->alias( 'basename' );

		// Capture the final update_option call.
		$captured_archives = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$captured_archives ) {
				if ( $name === 'egps_zip_archives' ) {
					$captured_archives = $value;
				}
				return true;
			}
		);

		// Should NOT reschedule since all attachments fit in one batch.
		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::process_batch( 42 );

		$this->assertSame( 'complete', $captured_archives[42]['status'] );
		$this->assertArrayHasKey( 'created_at', $captured_archives[42] );
		$this->assertArrayHasKey( 'url', $captured_archives[42] );
		$this->assertArrayHasKey( 'file_path', $captured_archives[42] );
		$this->assertArrayNotHasKey( 'last_offset', $captured_archives[42] );

		// Clean up temp files.
		@unlink( $tmp1 );
		@unlink( $tmp2 );
		// Clean up the generated ZIP and temp directory.
		if ( isset( $captured_archives[42]['file_path'] ) && file_exists( $captured_archives[42]['file_path'] ) ) {
			@unlink( $captured_archives[42]['file_path'] );
		}
		@unlink( $archive_dir . '/index.php' );
		@rmdir( $archive_dir );
		@rmdir( $base_dir );

		unset( $GLOBALS['egps_wp_query_mock'] );
	}

	/**
	 * Test that process_batch reschedules when more attachments remain.
	 */
	public function test_process_batch_reschedules_for_remaining_attachments(): void {
		// Use a real temp directory so ZipArchive can write files.
		$base_dir    = sys_get_temp_dir() . '/egps-test-uploads-' . uniqid();
		$archive_dir = $base_dir . '/egps-archives';
		mkdir( $archive_dir, 0777, true );

		$zip_path = $archive_dir . '/egps-archive-42-abc123def456.zip';

		$entry = array(
			'status'      => 'generating',
			'token'       => 'abc123def456',
			'last_offset' => 0,
			'file_path'   => $zip_path,
			'url'         => 'https://example.com/wp-content/uploads/egps-archives/egps-archive-42-abc123def456.zip',
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $entry ) {
				if ( $name === 'egps_zip_archives' ) {
					return array( 42 => $entry );
				}
				return $default;
			}
		);

		$upload_dir = array(
			'basedir' => $base_dir,
			'baseurl' => 'https://example.com/wp-content/uploads',
		);
		Functions\expect( 'wp_get_upload_dir' )->andReturn( $upload_dir );
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				// Use a tiny batch size so we trigger rescheduling with just 2 found.
				if ( $tag === 'egps_archive_batch_size' ) {
					return 1;
				}
				return $value;
			}
		);

		// 1 attachment in this batch, but 2 total.
		$att1     = new \stdClass();
		$att1->ID = 100;

		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts        = array( $att1 );
		$GLOBALS['egps_wp_query_mock']->found_posts  = 2;

		$tmp1 = tempnam( sys_get_temp_dir(), 'egps' );
		file_put_contents( $tmp1, 'fake image data' );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $tmp1 );
		Functions\when( 'get_attached_file' )->justReturn( $tmp1 );
		Functions\when( 'wp_basename' )->alias( 'basename' );

		$captured_archives = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$captured_archives ) {
				if ( $name === 'egps_zip_archives' ) {
					$captured_archives = $value;
				}
				return true;
			}
		);

		// Should reschedule for the next batch.
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->withArgs(
				function ( $timestamp, $hook, $args ) {
					return $hook === 'egps_archive_build_batch'
						&& $args === array( 42 );
				}
			);

		Archive::process_batch( 42 );

		$this->assertSame( 'generating', $captured_archives[42]['status'] );
		$this->assertSame( 1, $captured_archives[42]['last_offset'] );

		@unlink( $tmp1 );
		if ( isset( $captured_archives[42]['file_path'] ) && file_exists( $captured_archives[42]['file_path'] ) ) {
			@unlink( $captured_archives[42]['file_path'] );
		}
		@unlink( $archive_dir . '/index.php' );
		@rmdir( $archive_dir );
		@rmdir( $base_dir );

		unset( $GLOBALS['egps_wp_query_mock'] );
	}

	/**
	 * Test that process_batch skips missing files without failing.
	 */
	public function test_process_batch_skips_missing_files(): void {
		$entry = array(
			'status'      => 'pending',
			'token'       => 'abc123def456',
			'last_offset' => 0,
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $entry ) {
				if ( $name === 'egps_zip_archives' ) {
					return array( 42 => $entry );
				}
				return $default;
			}
		);

		// Use a real temp directory so ZipArchive can write files.
		$base_dir    = sys_get_temp_dir() . '/egps-test-uploads-' . uniqid();
		$archive_dir = $base_dir . '/egps-archives';
		mkdir( $archive_dir, 0777, true );

		$upload_dir = array(
			'basedir' => $base_dir,
			'baseurl' => 'https://example.com/wp-content/uploads',
		);
		Functions\expect( 'wp_get_upload_dir' )->andReturn( $upload_dir );
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );

		$att1     = new \stdClass();
		$att1->ID = 100;

		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts        = array( $att1 );
		$GLOBALS['egps_wp_query_mock']->found_posts  = 1;

		// Return a path that does not exist.
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/nonexistent/image.jpg' );
		Functions\when( 'get_attached_file' )->justReturn( '/nonexistent/image.jpg' );
		Functions\when( 'wp_basename' )->alias( 'basename' );

		$captured_archives = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$captured_archives ) {
				if ( $name === 'egps_zip_archives' ) {
					$captured_archives = $value;
				}
				return true;
			}
		);

		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::process_batch( 42 );

		// Should still complete even though the file was skipped.
		$this->assertSame( 'complete', $captured_archives[42]['status'] );

		// Clean up temp directory.
		if ( isset( $captured_archives[42]['file_path'] ) && file_exists( $captured_archives[42]['file_path'] ) ) {
			@unlink( $captured_archives[42]['file_path'] );
		}
		@unlink( $archive_dir . '/index.php' );
		@rmdir( $archive_dir );
		@rmdir( $base_dir );

		unset( $GLOBALS['egps_wp_query_mock'] );
	}

	/**
	 * Test that duplicate basenames get the attachment ID appended.
	 */
	public function test_process_batch_deduplicates_filenames(): void {
		$entry = array(
			'status'      => 'pending',
			'token'       => 'abc123def456',
			'last_offset' => 0,
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $entry ) {
				if ( $name === 'egps_zip_archives' ) {
					return array( 42 => $entry );
				}
				return $default;
			}
		);

		// Use a real temp directory so ZipArchive can write files.
		$base_dir    = sys_get_temp_dir() . '/egps-test-uploads-' . uniqid();
		$archive_dir = $base_dir . '/egps-archives';
		mkdir( $archive_dir, 0777, true );

		$upload_dir = array(
			'basedir' => $base_dir,
			'baseurl' => 'https://example.com/wp-content/uploads',
		);
		Functions\expect( 'wp_get_upload_dir' )->andReturn( $upload_dir );
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value ) => $value );

		// Two attachments with the same basename.
		$att1     = new \stdClass();
		$att1->ID = 100;
		$att2     = new \stdClass();
		$att2->ID = 101;

		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts        = array( $att1, $att2 );
		$GLOBALS['egps_wp_query_mock']->found_posts  = 2;

		$tmp1 = tempnam( sys_get_temp_dir(), 'egps' );
		$tmp2 = tempnam( sys_get_temp_dir(), 'egps' );
		file_put_contents( $tmp1, 'image data 1' );
		file_put_contents( $tmp2, 'image data 2' );

		// Both return the same basename.
		Functions\when( 'wp_get_original_image_path' )->alias(
			fn( $id ) => $id === 100 ? $tmp1 : $tmp2
		);
		Functions\when( 'get_attached_file' )->alias(
			fn( $id ) => $id === 100 ? $tmp1 : $tmp2
		);
		// Force both to return the same name.
		Functions\when( 'wp_basename' )->justReturn( 'IMG_0001.jpg' );

		$captured_archives = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$captured_archives ) {
				if ( $name === 'egps_zip_archives' ) {
					$captured_archives = $value;
				}
				return true;
			}
		);
		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::process_batch( 42 );

		// Verify the ZIP was created and contains 2 entries with different names.
		$zip_path = $captured_archives[42]['file_path'] ?? '';
		$this->assertFileExists( $zip_path );

		$zip = new \ZipArchive();
		$zip->open( $zip_path );
		$this->assertSame( 2, $zip->numFiles );

		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();

		// First keeps original name, second gets ID suffix.
		$this->assertContains( 'IMG_0001.jpg', $names );
		$this->assertContains( 'IMG_0001-101.jpg', $names );

		@unlink( $tmp1 );
		@unlink( $tmp2 );
		@unlink( $zip_path );
		@unlink( $archive_dir . '/index.php' );
		@rmdir( $archive_dir );
		@rmdir( $base_dir );

		unset( $GLOBALS['egps_wp_query_mock'] );
	}

	/**
	 * Test that delete_archive removes an entry.
	 */
	public function test_delete_archive_removes_entry(): void {
		$existing = array(
			42 => array( 'status' => 'complete' ),
			87 => array( 'status' => 'complete' ),
		);

		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( $existing );

		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return $name === 'egps_zip_archives'
						&& ! isset( $value[42] )
						&& isset( $value[87] )
						&& count( $value ) === 1;
				}
			)
			->andReturn( true );

		Archive::delete_archive( 42 );

		// Mockery enforces the ->once()->withArgs() expectations above; this confirms we reached here.
		$this->assertTrue( true );
	}
}
