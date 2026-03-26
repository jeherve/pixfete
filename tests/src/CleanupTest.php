<?php
/**
 * Tests for the Cleanup class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Cleanup;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Test permanent deletion of all event data via Cleanup::delete_event().
 */
class CleanupTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// is_wp_error() is used internally by Cleanup::delete_event().
		Functions\when( 'is_wp_error' )->alias(
			function ( $v ) {
				return $v instanceof WP_Error;
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

	/**
	 * Test that delete_event returns a WP_Error for a non-existent page.
	 *
	 * REST::validate_page() checks get_post_status() first; when it returns
	 * false (post does not exist), we should get back egps_invalid_page
	 * without touching any deletion functions.
	 */
	public function test_delete_event_returns_error_for_nonexistent_page(): void {
		Functions\expect( 'get_post_status' )
			->once()
			->with( 999 )
			->andReturn( false );

		$result = Cleanup::delete_event( 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_page', $result->get_error_code() );
	}

	/**
	 * Test that delete_event returns a WP_Error when the page lacks the event block.
	 *
	 * A published page that does not contain our block is not a valid event
	 * page. REST::validate_page() checks has_block(); we should receive an
	 * error before any deletion is attempted.
	 */
	public function test_delete_event_returns_error_for_page_without_block(): void {
		Functions\expect( 'get_post_status' )
			->once()
			->with( 42 )
			->andReturn( 'publish' );

		Functions\expect( 'get_post_type' )
			->once()
			->with( 42 )
			->andReturn( 'page' );

		Functions\expect( 'has_block' )
			->once()
			->with( 'event-guest-photos-sharing/event-album', 42 )
			->andReturn( false );

		$result = Cleanup::delete_event( 42 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test that delete_event deletes attachments, archive, and page when everything is present.
	 *
	 * This is the happy-path scenario: the page has two attachments, a
	 * complete ZIP archive on disk, and all deletion functions are called
	 * with the correct arguments in the correct order.
	 */
	public function test_delete_event_deletes_everything(): void {
		// Make the page pass REST::validate_page().
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'get_post_type' )->once()->with( 42 )->andReturn( 'page' );
		Functions\expect( 'has_block' )
			->once()
			->with( 'event-guest-photos-sharing/event-album', 42 )
			->andReturn( true );
		Functions\expect( 'get_post_field' )
			->once()
			->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'event-guest-photos-sharing/event-album',
						'attrs'     => array(),
					),
				)
			);

		// Two attachments returned by WP_Query.
		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts       = array( 100, 101 );
		$GLOBALS['egps_wp_query_mock']->found_posts = 2;

		// Both attachments must be force-deleted.
		Functions\expect( 'wp_delete_attachment' )
			->twice()
			->withArgs(
				function ( $id, $force ) {
					return in_array( $id, array( 100, 101 ), true ) && true === $force;
				}
			);

		// Archive exists with a file on disk.
		// get_option is called twice: once by Archive::get_archive() and once
		// by Archive::delete_archive() (which calls get_archives() internally).
		$archive_path = '/tmp/egps-archive-42-abc123.zip';
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = array() ) use ( $archive_path ) {
				if ( 'egps_zip_archives' === $name ) {
					return array(
						42 => array(
							'status'    => 'complete',
							'file_path' => $archive_path,
						),
					);
				}
				return $default;
			}
		);

		// The ZIP file must be removed.
		Functions\expect( 'wp_delete_file' )
			->once()
			->with( $archive_path );

		// The archive option entry must be removed.
		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				function ( $name, $value ) {
					return 'egps_zip_archives' === $name && ! isset( $value[42] );
				}
			)
			->andReturn( true );

		// The page itself must be force-deleted.
		Functions\expect( 'wp_delete_post' )
			->once()
			->with( 42, true );

		// The cleanup action must fire with page ID and summary.
		$fired_args = null;
		Functions\expect( 'do_action' )
			->once()
			->withArgs(
				function ( $hook, $page_id, $summary ) use ( &$fired_args ) {
					if ( 'egps_after_event_cleanup' === $hook ) {
						$fired_args = array( $page_id, $summary );
						return true;
					}
					return false;
				}
			);

		$result = Cleanup::delete_event( 42 );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['deleted_attachments'] );
		$this->assertTrue( $result['deleted_archive'] );
		$this->assertTrue( $result['deleted_page'] );

		// Verify action was fired with the right arguments.
		$this->assertNotNull( $fired_args );
		$this->assertSame( 42, $fired_args[0] );
		$this->assertSame( 2, $fired_args[1]['deleted_attachments'] );
		$this->assertTrue( $fired_args[1]['deleted_archive'] );
	}

	/**
	 * Test that delete_event succeeds gracefully when there are no attachments or archive.
	 *
	 * A newly created event page with no photos and no generated archive
	 * should still be deletable. wp_delete_file must never be called, and
	 * the summary should reflect zero attachments and no archive.
	 */
	public function test_delete_event_succeeds_without_archive(): void {
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'get_post_type' )->once()->with( 42 )->andReturn( 'page' );
		Functions\expect( 'has_block' )
			->once()
			->with( 'event-guest-photos-sharing/event-album', 42 )
			->andReturn( true );
		Functions\expect( 'get_post_field' )
			->once()
			->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'event-guest-photos-sharing/event-album',
						'attrs'     => array(),
					),
				)
			);

		// No attachments.
		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts       = array();
		$GLOBALS['egps_wp_query_mock']->found_posts = 0;

		// No archive entry.
		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array() );

		// wp_delete_file must never be called when there is no archive.
		Functions\expect( 'wp_delete_file' )->never();

		Functions\expect( 'wp_delete_post' )->once()->with( 42, true );

		Functions\expect( 'do_action' )->once()->withAnyArgs();

		$result = Cleanup::delete_event( 42 );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['deleted_attachments'] );
		$this->assertFalse( $result['deleted_archive'] );
		$this->assertTrue( $result['deleted_page'] );
	}

	/**
	 * Test that the egps_after_event_cleanup action fires with page ID and summary.
	 *
	 * Third-party code may hook into this action for audit logging or cache
	 * invalidation. We verify that it fires exactly once with the correct
	 * page ID and a summary array containing the expected keys.
	 */
	public function test_delete_event_fires_action(): void {
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'get_post_type' )->once()->with( 42 )->andReturn( 'page' );
		Functions\expect( 'has_block' )
			->once()
			->with( 'event-guest-photos-sharing/event-album', 42 )
			->andReturn( true );
		Functions\expect( 'get_post_field' )
			->once()
			->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'event-guest-photos-sharing/event-album',
						'attrs'     => array(),
					),
				)
			);

		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts       = array();
		$GLOBALS['egps_wp_query_mock']->found_posts = 0;

		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'wp_delete_file' )->never();
		Functions\expect( 'wp_delete_post' )->once()->with( 42, true );

		$action_fired   = false;
		$action_page_id = null;
		$action_summary = null;

		Functions\expect( 'do_action' )
			->once()
			->withArgs(
				function ( $hook, $page_id, $summary ) use ( &$action_fired, &$action_page_id, &$action_summary ) {
					if ( 'egps_after_event_cleanup' === $hook ) {
						$action_fired   = true;
						$action_page_id = $page_id;
						$action_summary = $summary;
						return true;
					}
					return false;
				}
			);

		Cleanup::delete_event( 42 );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertTrue( $action_fired );
		$this->assertSame( 42, $action_page_id );
		$this->assertIsArray( $action_summary );
		$this->assertArrayHasKey( 'deleted_attachments', $action_summary );
		$this->assertArrayHasKey( 'deleted_archive', $action_summary );
		$this->assertArrayHasKey( 'deleted_page', $action_summary );
	}
}
