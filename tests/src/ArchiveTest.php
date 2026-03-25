<?php
/**
 * Tests for the Archive class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Archive;
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
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
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
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
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
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
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
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
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
		Functions\expect( 'get_post_field' )->once()->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
					'attrs'     => array(),
				),
			)
		);

		Functions\expect( 'wp_schedule_single_event' )->never();

		Archive::check_events();

		$this->assertTrue( true );
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
