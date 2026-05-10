<?php
/**
 * Tests for the Cleanup class.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Pixfete\Cleanup;
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
	 * false (post does not exist), we should get back pixfete_invalid_page
	 * without touching any deletion functions.
	 */
	public function test_delete_event_returns_error_for_nonexistent_page(): void {
		Functions\expect( 'get_post_status' )
			->once()
			->with( 999 )
			->andReturn( false );

		$result = Cleanup::delete_event( 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pixfete_invalid_page', $result->get_error_code() );
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
			->with( 'pixfete/event-album', 42 )
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
			->with( 'pixfete/event-album', 42 )
			->andReturn( true );
		Functions\expect( 'get_post_field' )
			->once()
			->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'pixfete/event-album',
						'attrs'     => array(),
					),
				)
			);

		// Two attachments returned by WP_Query.
		$GLOBALS['pixfete_wp_query_mock']              = new \stdClass();
		$GLOBALS['pixfete_wp_query_mock']->posts       = array( 100, 101 );
		$GLOBALS['pixfete_wp_query_mock']->found_posts = 2;

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
				if ( 'pixfete_zip_archives' === $name ) {
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
					return 'pixfete_zip_archives' === $name && ! isset( $value[42] );
				}
			)
			->andReturn( true );

		// Any orphaned batch cron jobs for this page must be cleared.
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'pixfete_archive_build_batch', array( 42 ) );

		// No slideshow pages reference this event.
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

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
					if ( 'pixfete_after_event_cleanup' === $hook ) {
						$fired_args = array( $page_id, $summary );
						return true;
					}
					return false;
				}
			);

		$result = Cleanup::delete_event( 42 );

		unset( $GLOBALS['pixfete_wp_query_mock'] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['deleted_attachments'] );
		$this->assertTrue( $result['deleted_archive'] );
		$this->assertSame( 0, $result['deleted_slideshow_pages'] );
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
			->with( 'pixfete/event-album', 42 )
			->andReturn( true );
		Functions\expect( 'get_post_field' )
			->once()
			->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'pixfete/event-album',
						'attrs'     => array(),
					),
				)
			);

		// No attachments.
		$GLOBALS['pixfete_wp_query_mock']              = new \stdClass();
		$GLOBALS['pixfete_wp_query_mock']->posts       = array();
		$GLOBALS['pixfete_wp_query_mock']->found_posts = 0;

		// No archive entry.
		Functions\expect( 'get_option' )
			->once()
			->with( 'pixfete_zip_archives', array() )
			->andReturn( array() );

		// wp_delete_file must never be called when there is no archive.
		Functions\expect( 'wp_delete_file' )->never();

		// No slideshow pages reference this event.
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		Functions\expect( 'wp_delete_post' )->once()->with( 42, true );

		Functions\expect( 'do_action' )->once()->withAnyArgs();

		$result = Cleanup::delete_event( 42 );

		unset( $GLOBALS['pixfete_wp_query_mock'] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['deleted_attachments'] );
		$this->assertFalse( $result['deleted_archive'] );
		$this->assertSame( 0, $result['deleted_slideshow_pages'] );
		$this->assertTrue( $result['deleted_page'] );
	}

	/**
	 * Test that slideshow pages referencing the deleted event are also removed.
	 *
	 * When a page contains an event-slideshow block whose eventPageId matches
	 * the event being deleted, that page should be permanently deleted to
	 * avoid orphaned slideshow pages pointing at non-existent events.
	 */
	public function test_delete_event_removes_slideshow_pages(): void {
		// Make the page pass REST::validate_page().
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'get_post_type' )->once()->with( 42 )->andReturn( 'page' );
		Functions\expect( 'has_block' )
			->once()
			->with( 'pixfete/event-album', 42 )
			->andReturn( true );

		// get_post_field is called twice: once by validate_page() and once
		// for the slideshow page content check.
		Functions\expect( 'get_post_field' )
			->andReturnUsing(
				function ( $field, $post_id ) {
					if ( 42 === $post_id ) {
						return '<!-- wp:pixfete/event-album -->';
					}
					if ( 200 === $post_id ) {
						return '<!-- wp:pixfete/event-slideshow {"eventPageId":42} /-->';
					}
					return '';
				}
			);

		// parse_blocks is called twice: once for validate_page(), once for
		// the slideshow page content.
		Functions\expect( 'parse_blocks' )
			->andReturnUsing(
				function ( $content ) {
					if ( str_contains( $content, 'event-album' ) ) {
						return array(
							array(
								'blockName' => 'pixfete/event-album',
								'attrs'     => array(),
							),
						);
					}
					return array(
						array(
							'blockName'   => 'pixfete/event-slideshow',
							'attrs'       => array( 'eventPageId' => 42 ),
							'innerBlocks' => array(),
						),
					);
				}
			);

		// No attachments.
		$GLOBALS['pixfete_wp_query_mock']              = new \stdClass();
		$GLOBALS['pixfete_wp_query_mock']->posts       = array();
		$GLOBALS['pixfete_wp_query_mock']->found_posts = 0;

		// No archive.
		Functions\expect( 'get_option' )
			->once()
			->with( 'pixfete_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'wp_delete_file' )->never();

		// get_posts returns one slideshow page referencing event 42.
		$slideshow_page     = new \stdClass();
		$slideshow_page->ID = 200;
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( $slideshow_page ) );

		// wp_delete_post should be called twice: once for slideshow page 200,
		// once for the event page 42.
		$deleted_post_ids = array();
		Functions\expect( 'wp_delete_post' )
			->twice()
			->withArgs(
				function ( $id, $force ) use ( &$deleted_post_ids ) {
					$deleted_post_ids[] = $id;
					return true === $force;
				}
			);

		Functions\expect( 'do_action' )->once()->withAnyArgs();

		$result = Cleanup::delete_event( 42 );

		unset( $GLOBALS['pixfete_wp_query_mock'] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['deleted_slideshow_pages'] );
		$this->assertContains( 200, $deleted_post_ids, 'Slideshow page 200 should be deleted' );
		$this->assertContains( 42, $deleted_post_ids, 'Event page 42 should be deleted' );
	}

	/**
	 * Test that slideshow pages referencing a different event are NOT removed.
	 *
	 * A slideshow page whose eventPageId points to a different event must
	 * survive the deletion of the current event. Only exact matches should
	 * be removed.
	 */
	public function test_delete_event_does_not_remove_unrelated_slideshow_pages(): void {
		// Make the page pass REST::validate_page().
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'get_post_type' )->once()->with( 42 )->andReturn( 'page' );
		Functions\expect( 'has_block' )
			->once()
			->with( 'pixfete/event-album', 42 )
			->andReturn( true );

		Functions\expect( 'get_post_field' )
			->andReturnUsing(
				function ( $field, $post_id ) {
					if ( 42 === $post_id ) {
						return '<!-- wp:pixfete/event-album -->';
					}
					if ( 300 === $post_id ) {
						// This slideshow references event 99, not event 42.
						return '<!-- wp:pixfete/event-slideshow {"eventPageId":99} /-->';
					}
					return '';
				}
			);

		Functions\expect( 'parse_blocks' )
			->andReturnUsing(
				function ( $content ) {
					if ( str_contains( $content, 'event-album' ) ) {
						return array(
							array(
								'blockName' => 'pixfete/event-album',
								'attrs'     => array(),
							),
						);
					}
					return array(
						array(
							'blockName'   => 'pixfete/event-slideshow',
							'attrs'       => array( 'eventPageId' => 99 ),
							'innerBlocks' => array(),
						),
					);
				}
			);

		// No attachments.
		$GLOBALS['pixfete_wp_query_mock']              = new \stdClass();
		$GLOBALS['pixfete_wp_query_mock']->posts       = array();
		$GLOBALS['pixfete_wp_query_mock']->found_posts = 0;

		// No archive.
		Functions\expect( 'get_option' )
			->once()
			->with( 'pixfete_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'wp_delete_file' )->never();

		// get_posts returns a slideshow page referencing a DIFFERENT event.
		$unrelated_page     = new \stdClass();
		$unrelated_page->ID = 300;
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( $unrelated_page ) );

		// wp_delete_post should only be called once — for the event page itself.
		Functions\expect( 'wp_delete_post' )
			->once()
			->with( 42, true );

		Functions\expect( 'do_action' )->once()->withAnyArgs();

		$result = Cleanup::delete_event( 42 );

		unset( $GLOBALS['pixfete_wp_query_mock'] );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['deleted_slideshow_pages'] );
	}

	/**
	 * Test that slideshow blocks nested inside container blocks are still found and deleted.
	 *
	 * Slideshow blocks may be placed inside a group, columns, or any other
	 * container block. The recursive has_slideshow_for_event() method must
	 * walk the innerBlocks tree to locate them — a flat search is not enough.
	 * This test wraps the slideshow block one level deep inside a core/group
	 * block and verifies the slideshow page is still deleted.
	 */
	public function test_delete_event_removes_slideshow_in_nested_blocks(): void {
		// Make the page pass REST::validate_page().
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'get_post_type' )->once()->with( 42 )->andReturn( 'page' );
		Functions\expect( 'has_block' )
			->once()
			->with( 'pixfete/event-album', 42 )
			->andReturn( true );

		// get_post_field is called twice: once by validate_page() for event page 42,
		// and once for the slideshow page content (post ID 200).
		Functions\expect( 'get_post_field' )
			->andReturnUsing(
				function ( $field, $post_id ) {
					if ( 42 === $post_id ) {
						return '<!-- wp:pixfete/event-album -->';
					}
					if ( 200 === $post_id ) {
						return '<!-- wp:core/group --><!-- wp:pixfete/event-slideshow {"eventPageId":42} /--><!-- /wp:core/group -->';
					}
					return '';
				}
			);

		// parse_blocks is called twice: once for validate_page(), once for
		// the slideshow page. The slideshow block is nested inside a core/group.
		Functions\expect( 'parse_blocks' )
			->andReturnUsing(
				function ( $content ) {
					if ( str_contains( $content, 'event-album' ) ) {
						return array(
							array(
								'blockName'   => 'pixfete/event-album',
								'attrs'       => array(),
								'innerBlocks' => array(),
							),
						);
					}
					// Slideshow block wrapped inside a core/group block.
					return array(
						array(
							'blockName'   => 'core/group',
							'attrs'       => array(),
							'innerBlocks' => array(
								array(
									'blockName'   => 'pixfete/event-slideshow',
									'attrs'       => array( 'eventPageId' => 42 ),
									'innerBlocks' => array(),
								),
							),
						),
					);
				}
			);

		// No attachments.
		$GLOBALS['pixfete_wp_query_mock']              = new \stdClass();
		$GLOBALS['pixfete_wp_query_mock']->posts       = array();
		$GLOBALS['pixfete_wp_query_mock']->found_posts = 0;

		// No archive.
		Functions\expect( 'get_option' )
			->once()
			->with( 'pixfete_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'wp_delete_file' )->never();

		// get_posts returns one slideshow page that wraps the slideshow in a group block.
		$slideshow_page     = new \stdClass();
		$slideshow_page->ID = 200;
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( $slideshow_page ) );

		// wp_delete_post should be called twice: once for the nested slideshow
		// page 200, and once for the event page 42.
		$deleted_post_ids = array();
		Functions\expect( 'wp_delete_post' )
			->twice()
			->withArgs(
				function ( $id, $force ) use ( &$deleted_post_ids ) {
					$deleted_post_ids[] = $id;
					return true === $force;
				}
			)
			->andReturn( new \stdClass() );

		Functions\expect( 'do_action' )->once()->withAnyArgs();

		$result = Cleanup::delete_event( 42 );

		unset( $GLOBALS['pixfete_wp_query_mock'] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['deleted_slideshow_pages'], 'Nested slideshow page should be counted as deleted' );
		$this->assertContains( 200, $deleted_post_ids, 'Nested slideshow page 200 should be deleted' );
		$this->assertContains( 42, $deleted_post_ids, 'Event page 42 should be deleted' );
	}

	/**
	 * Test that the pixfete_after_event_cleanup action fires with page ID and summary.
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
			->with( 'pixfete/event-album', 42 )
			->andReturn( true );
		Functions\expect( 'get_post_field' )
			->once()
			->andReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'pixfete/event-album',
						'attrs'     => array(),
					),
				)
			);

		$GLOBALS['pixfete_wp_query_mock']              = new \stdClass();
		$GLOBALS['pixfete_wp_query_mock']->posts       = array();
		$GLOBALS['pixfete_wp_query_mock']->found_posts = 0;

		Functions\expect( 'get_option' )
			->once()
			->with( 'pixfete_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'wp_delete_file' )->never();

		// No slideshow pages reference this event.
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		Functions\expect( 'wp_delete_post' )->once()->with( 42, true );

		$action_fired   = false;
		$action_page_id = null;
		$action_summary = null;

		Functions\expect( 'do_action' )
			->once()
			->withArgs(
				function ( $hook, $page_id, $summary ) use ( &$action_fired, &$action_page_id, &$action_summary ) {
					if ( 'pixfete_after_event_cleanup' === $hook ) {
						$action_fired   = true;
						$action_page_id = $page_id;
						$action_summary = $summary;
						return true;
					}
					return false;
				}
			);

		Cleanup::delete_event( 42 );

		unset( $GLOBALS['pixfete_wp_query_mock'] );

		$this->assertTrue( $action_fired );
		$this->assertSame( 42, $action_page_id );
		$this->assertIsArray( $action_summary );
		$this->assertArrayHasKey( 'deleted_attachments', $action_summary );
		$this->assertArrayHasKey( 'deleted_archive', $action_summary );
		$this->assertArrayHasKey( 'deleted_slideshow_pages', $action_summary );
		$this->assertArrayHasKey( 'deleted_page', $action_summary );
	}
}
