<?php
/**
 * Tests for the REST DELETE /events/{page_id} cleanup endpoint.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Pixfete\REST;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test the DELETE /events/{page_id} REST endpoint: permission callback
 * and handler logic, including delegation to Cleanup::delete_event().
 */
class RestCleanupTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// is_wp_error() is used internally by handle_cleanup() and Cleanup::delete_event().
		Functions\when( 'is_wp_error' )->alias(
			function ( $v ) {
				return $v instanceof WP_Error;
			}
		);
	}

	/**
	 * Tear down Brain Monkey and clean up globals after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['egps_wp_query_mock'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper to build a mock WP_REST_Request with the given params.
	 *
	 * @param array $params Parameters to set on the request (key => value).
	 * @return WP_REST_Request
	 */
	private function make_request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Test that the permission callback rejects users without delete_post capability.
	 *
	 * A user who cannot delete the event page must receive a WP_Error with
	 * the egps_forbidden code and a 403 status, not a PHP error or false.
	 */
	public function test_permission_rejects_unauthorized_user(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'delete_post', 42 )
			->andReturn( false );

		$request = $this->make_request( array( 'page_id' => '42' ) );
		$result  = REST::check_cleanup_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'egps_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test that the permission callback allows users with delete_post capability.
	 *
	 * An administrator or any user with the delete_post capability for the
	 * given page must receive exactly true (not just truthy) so that the
	 * WordPress REST framework proceeds to the callback.
	 */
	public function test_permission_allows_authorized_user(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'delete_post', 42 )
			->andReturn( true );

		$request = $this->make_request( array( 'page_id' => '42' ) );
		$result  = REST::check_cleanup_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test that handle_cleanup returns a 200 response summarising a successful deletion.
	 *
	 * When Cleanup::delete_event() succeeds (page exists, no attachments,
	 * no archive), the handler must wrap the summary array in a
	 * WP_REST_Response with HTTP 200 and include deleted_page => true.
	 */
	public function test_handle_cleanup_returns_summary_on_success(): void {
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

		// No attachments, no archive.
		$GLOBALS['egps_wp_query_mock']              = new \stdClass();
		$GLOBALS['egps_wp_query_mock']->posts       = array();
		$GLOBALS['egps_wp_query_mock']->found_posts = 0;

		Functions\expect( 'get_option' )
			->once()
			->with( 'egps_zip_archives', array() )
			->andReturn( array() );

		Functions\expect( 'wp_delete_file' )->never();

		// No slideshow pages reference this event.
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		Functions\expect( 'wp_delete_post' )->once()->with( 42, true );
		Functions\expect( 'do_action' )->once()->withAnyArgs();

		$request = $this->make_request( array( 'page_id' => '42' ) );
		$result  = REST::handle_cleanup( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );

		$data = $result->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['deleted_page'] );
	}

	/**
	 * Test that handle_cleanup returns a WP_Error for a non-existent page.
	 *
	 * When Cleanup::delete_event() returns a WP_Error (e.g., because
	 * get_post_status() returns false), handle_cleanup must propagate that
	 * error directly rather than wrapping it in a response.
	 */
	public function test_handle_cleanup_returns_error_for_invalid_page(): void {
		Functions\expect( 'get_post_status' )
			->once()
			->with( 999 )
			->andReturn( false );

		$request = $this->make_request( array( 'page_id' => '999' ) );
		$result  = REST::handle_cleanup( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_page', $result->get_error_code() );
	}

	/**
	 * Test that page_id is cast to int before being passed to current_user_can.
	 *
	 * Route params always arrive as strings from the URL pattern. The
	 * permission callback must cast them to int so capability checks receive
	 * a proper integer (e.g., 42 rather than "42abc").
	 */
	public function test_page_id_is_cast_to_int(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->withArgs(
				function ( $cap, $id ) {
					return 'delete_post' === $cap && 42 === $id && is_int( $id );
				}
			)
			->andReturn( true );

		$request = $this->make_request( array( 'page_id' => '42abc' ) );
		$result  = REST::check_cleanup_permission( $request );

		$this->assertTrue( $result );
	}
}
