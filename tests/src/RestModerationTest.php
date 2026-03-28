<?php
/**
 * Tests for the DELETE /photos/{page_id}/{attachment_id} moderation endpoint.
 *
 * Covers permission checks (authentication, capability, page assignment,
 * attachment ownership) and the handler logic (successful deletion,
 * failed deletion).
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\REST;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test the DELETE /photos/{page_id}/{attachment_id} REST endpoint:
 * permission callback and handler logic for single-photo moderation.
 */
class RestModerationTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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
	 * Test that the moderation DELETE route is registered.
	 *
	 * The register_routes() method must call register_rest_route() with
	 * a path matching /photos/{page_id}/{attachment_id} and the DELETE
	 * method so WordPress can dispatch moderation requests.
	 */
	public function testRegisterRoutesIncludesModerationEndpoint(): void {
		$registered_routes = array();

		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$registered_routes ) {
				$registered_routes[] = array(
					'namespace' => $namespace,
					'route'     => $route,
					'methods'   => $args['methods'] ?? '',
				);
			}
		);

		$rest = new REST();
		$rest->register_routes();

		$found = false;
		foreach ( $registered_routes as $r ) {
			if (
				'event-guest-photos-sharing/v1' === $r['namespace']
				&& '/photos/(?P<page_id>\d+)/(?P<attachment_id>\d+)' === $r['route']
				&& 'DELETE' === $r['methods']
			) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'DELETE /photos/{page_id}/{attachment_id} route was not registered.' );
	}

	/**
	 * Test that the permission callback rejects unauthenticated users.
	 *
	 * Guests who are not logged in must receive a 401 error, not a
	 * capability check, because they have no WordPress identity at all.
	 */
	public function testModerationPermissionDeniedWhenNotLoggedIn(): void {
		Functions\expect( 'is_user_logged_in' )
			->once()
			->andReturn( false );

		$request = $this->make_request(
			array(
				'page_id'       => '42',
				'attachment_id' => '100',
			)
		);

		$result = REST::check_moderation_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'egps_unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Test that the permission callback rejects users without the moderation capability.
	 *
	 * A logged-in user who lacks both egps_moderate_photos and manage_options
	 * must be rejected with a 403 error before any page or attachment checks run.
	 */
	public function testModerationPermissionDeniedWithoutCapability(): void {
		Functions\expect( 'is_user_logged_in' )
			->once()
			->andReturn( true );

		Functions\expect( 'current_user_can' )
			->with( 'egps_moderate_photos' )
			->andReturn( false );

		Functions\expect( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( false );

		$request = $this->make_request(
			array(
				'page_id'       => '42',
				'attachment_id' => '100',
			)
		);

		$result = REST::check_moderation_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'egps_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test that the permission callback rejects when attachment parent mismatches.
	 *
	 * Even if the user has the right capability and is assigned to the page,
	 * they must not be able to delete an attachment that belongs to a different
	 * page. This prevents cross-event deletion attacks.
	 */
	public function testModerationPermissionDeniedWhenAttachmentParentMismatch(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( true );
		Functions\expect( 'current_user_can' )
			->with( 'egps_moderate_photos' )
			->andReturn( true );

		// validate_page() mocks.
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'get_post_type' )->once()->with( 42 )->andReturn( 'page' );
		Functions\expect( 'has_block' )
			->once()
			->with( 'event-guest-photos-sharing/event-album', 42 )
			->andReturn( true );
		// get_post_field is called twice: once by validate_page() -> get_block_attributes()
		// and once by Moderator::is_moderator_for_page().
		Functions\expect( 'get_post_field' )
			->twice()
			->andReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\expect( 'parse_blocks' )
			->twice()
			->andReturn(
				array(
					array(
						'blockName' => 'event-guest-photos-sharing/event-album',
						'attrs'     => array( 'moderators' => array( 7 ) ),
					),
				)
			);

		// is_moderator_for_page() — user 7 is in the moderators list.
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 7 );

		// The attachment exists.
		$attachment        = new \stdClass();
		$attachment->ID    = 100;
		Functions\expect( 'get_post' )->once()->with( 100 )->andReturn( $attachment );

		// But its parent is page 99, not 42 — mismatch.
		Functions\expect( 'wp_get_post_parent_id' )->once()->with( 100 )->andReturn( 99 );

		$request = $this->make_request(
			array(
				'page_id'       => '42',
				'attachment_id' => '100',
			)
		);

		$result = REST::check_moderation_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'egps_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test that handle_moderation deletes the attachment and returns 204.
	 *
	 * When wp_delete_attachment succeeds (returns a truthy post object),
	 * the handler must return a 204 No Content response with null data,
	 * indicating the photo was permanently removed.
	 */
	public function testHandleModerationDeletesAttachmentAndReturns204(): void {
		$deleted_post     = new \stdClass();
		$deleted_post->ID = 100;

		Functions\expect( 'wp_delete_attachment' )
			->once()
			->with( 100, true )
			->andReturn( $deleted_post );

		$request = $this->make_request( array( 'attachment_id' => '100' ) );
		$result  = REST::handle_moderation( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 204, $result->get_status() );
		$this->assertNull( $result->get_data() );
	}

	/**
	 * Test that handle_moderation returns 404 when deletion fails.
	 *
	 * When wp_delete_attachment returns false (e.g., attachment was already
	 * removed or the filesystem operation failed), the handler must return
	 * a 404 response so the client knows the photo could not be found or deleted.
	 */
	public function testHandleModerationReturns404WhenDeleteFails(): void {
		Functions\expect( 'wp_delete_attachment' )
			->once()
			->with( 100, true )
			->andReturn( false );

		$request = $this->make_request( array( 'attachment_id' => '100' ) );
		$result  = REST::handle_moderation( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 404, $result->get_status() );
	}
}
