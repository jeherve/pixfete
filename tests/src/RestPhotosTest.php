<?php
/**
 * Tests for the REST photo upload and gallery endpoints.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Cookie;
use Jeherve\Event_Guest_Photos_Sharing\REST;
use PHPUnit\Framework\TestCase;

/**
 * Test the REST photo upload and gallery retrieval endpoints.
 */
class RestPhotosTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Common stubs needed across most tests.
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $str ) {
				return trim( strip_tags( $str ) );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-value' );
		Functions\when( 'wp_unslash' )->alias(
			function ( $v ) {
				return is_string( $v ) ? stripslashes( $v ) : $v;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $v ) {
				return $v instanceof \WP_Error;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
			}
		);
	}

	/**
	 * Tear down Brain Monkey after each test.
	 */
	protected function tearDown(): void {
		unset( $_COOKIE['egps_42'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper to build a mock WP_REST_Request with params, headers, and file params.
	 *
	 * @param array $params      Body parameters.
	 * @param array $headers     Headers (key => value).
	 * @param array $file_params File upload parameters.
	 * @return \WP_REST_Request
	 */
	private function make_request( array $params = array(), array $headers = array(), array $file_params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		if ( ! empty( $file_params ) ) {
			$request->set_file_params( $file_params );
		}
		return $request;
	}

	/**
	 * Helper to stub page validation for a valid page with our block.
	 *
	 * @param int    $page_id  The page ID.
	 * @param string $password The block password attribute.
	 * @param int    $version  The eventVersion attribute.
	 * @param array  $extra    Extra block attributes to merge.
	 */
	private function stub_valid_page( int $page_id = 42, string $password = 'correct-password', int $version = 1, array $extra = array() ): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'has_block' )->justReturn( true );

		$attrs = array_merge(
			array(
				'password'     => $password,
				'eventVersion' => $version,
			),
			$extra
		);

		Functions\when( 'get_post_field' )->justReturn( '<!-- wp:event-guest-photos-sharing/event-album -->' );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
					'attrs'     => $attrs,
				),
			)
		);
	}

	/**
	 * Helper: build a valid cookie payload with consent=true.
	 *
	 * @param int  $page_id Page ID.
	 * @param int  $version Event version.
	 * @param bool $consent Whether consent is granted.
	 * @return array<string, mixed>
	 */
	private function make_cookie_payload( int $page_id = 42, int $version = 1, bool $consent = true ): array {
		return array(
			'page_id'       => $page_id,
			'event_version' => $version,
			'guest_name'    => 'Marie',
			'table_name'    => 'Table 3',
			'consent'       => $consent,
			'registered_at' => 1700000000,
			'expires_at'    => time() + 86400,
		);
	}

	/**
	 * Helper: set the cookie in $_COOKIE for the given payload.
	 *
	 * @param array $payload Cookie payload.
	 * @param int   $page_id Page ID (used for cookie name).
	 */
	private function set_cookie( array $payload, int $page_id = 42 ): void {
		$_COOKIE[ 'egps_' . $page_id ] = Cookie::sign( $payload );
	}

	// ─── §5: Route registration ──────────────────────────────────────

	/**
	 * Test that register_routes registers the upload and gallery endpoints.
	 */
	public function test_register_routes_registers_photo_endpoints(): void {
		$captured = array();
		Functions\expect( 'register_rest_route' )
			->times( 3 )
			->withArgs(
				function ( $namespace, $route, $args ) use ( &$captured ) {
					$captured[] = array(
						'namespace' => $namespace,
						'route'     => $route,
						'args'      => $args,
					);
					return true;
				}
			);

		( new REST() )->register_routes();

		// The second and third registrations should be the photo endpoints.
		$upload  = $captured[1] ?? null;
		$gallery = $captured[2] ?? null;

		$this->assertNotNull( $upload, 'Upload route must be registered.' );
		$this->assertSame( 'event-guest-photos-sharing/v1', $upload['namespace'] );
		$this->assertSame( '/photos/(?P<page_id>\d+)', $upload['route'] );
		$this->assertSame( 'POST', $upload['args']['methods'] );

		$this->assertNotNull( $gallery, 'Gallery route must be registered.' );
		$this->assertSame( 'event-guest-photos-sharing/v1', $gallery['namespace'] );
		$this->assertSame( '/photos/(?P<page_id>\d+)', $gallery['route'] );
		$this->assertSame( 'GET', $gallery['args']['methods'] );
	}

	// ─── §5a: Upload endpoint — permission checks ────────────────────

	/**
	 * Test upload rejected when cookie is missing.
	 */
	public function test_upload_rejected_when_cookie_missing(): void {
		$this->stub_valid_page();

		Functions\when( 'apply_filters' )->returnArg( 2 );

		unset( $_COOKIE['egps_42'] );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_cookie', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test upload rejected when consent is false.
	 */
	public function test_upload_rejected_when_consent_false(): void {
		$this->stub_valid_page();

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, false );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_no_consent', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test upload rejected when event_version mismatches.
	 */
	public function test_upload_rejected_when_event_version_mismatches(): void {
		// Block has version 2, cookie has version 1.
		$this->stub_valid_page( 42, 'correct-password', 2 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_event_version', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test upload rejected when date range is expired (endDate in the past).
	 */
	public function test_upload_rejected_when_date_range_expired(): void {
		$this->stub_valid_page(
			42,
			'correct-password',
			1,
			array(
				'startDate' => '2020-01-01',
				'endDate'   => '2020-01-02',
			)
		);

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp = null, $timezone = null ) {
				// Return "today" as 2026-03-19 (after end date).
				return '2026-03-19';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_event_expired', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test upload rejected when guest upload limit is reached.
	 */
	public function test_upload_rejected_when_guest_upload_limit_reached(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		// Set upload limit to 5, and current count to 5.
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, ...$args ) {
				if ( 'egps_max_uploads_per_guest' === $filter ) {
					return 5;
				}
				return $args[0];
			}
		);

		// WP_Query mock: found_posts = 5 (at limit).
		$wp_query_mock              = new \stdClass();
		$wp_query_mock->found_posts = 5;

		$GLOBALS['egps_wp_query_mock'] = $wp_query_mock;

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_upload_limit_reached', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		unset( $GLOBALS['egps_wp_query_mock'] );
	}

	/**
	 * Test upload permission passes with valid cookie and consent.
	 */
	public function test_upload_permission_passes_with_valid_cookie(): void {
		$this->stub_valid_page();

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertTrue( $result );
	}

	// ─── §5a: Upload endpoint — handler ──────────────────────────────

	/**
	 * Test successful upload returns 201 with attachment data.
	 */
	public function test_upload_success_returns_201_with_attachment_data(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => 'jpg',
				'type'            => 'image/jpeg',
				'proper_filename' => false,
			)
		);
		Functions\when( 'wp_handle_upload' )->justReturn(
			array(
				'file' => '/tmp/uploads/photo.jpg',
				'url'  => 'https://example.com/wp-content/uploads/photo.jpg',
				'type' => 'image/jpeg',
			)
		);

		// Stub Upload class dependencies.
		Functions\when( 'wp_insert_attachment' )->justReturn( 123 );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( array() );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $name ) {
				return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name );
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		// Stubs for Upload::get_allowed_mime_types() -> server_supports_heic().
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\when( 'wp_get_attachment_image_src' )->justReturn(
			array( 'https://example.com/wp-content/uploads/photo-150x150.jpg', 150, 150, true )
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn(
			'https://example.com/wp-content/uploads/photo.jpg'
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				$meta = array(
					'_egps_guest_name'  => 'Marie',
					'_egps_table_name'  => 'Table 3',
					'_egps_uploaded_at' => 1706000000,
				);
				return $meta[ $key ] ?? '';
			}
		);

		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Create a real 1x1 pixel JPEG via GD so getimagesize() passes.
		$tmp_file = tempnam( sys_get_temp_dir(), 'egps_test_' ) . '.jpg';
		$img      = imagecreatetruecolor( 1, 1 );
		imagejpeg( $img, $tmp_file );
		imagedestroy( $img );

		$request = $this->make_request(
			array( 'page_id' => 42 ),
			array(),
			array(
				'photo' => array(
					'name'     => 'photo.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp_file,
					'error'    => 0,
					'size'     => 1024,
				),
			)
		);

		$response = REST::handle_photo_upload( $request );

		// Clean up.
		if ( file_exists( $tmp_file ) ) {
			unlink( $tmp_file );
		}

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 123, $data['id'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/photo-150x150.jpg', $data['thumbnail'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/photo.jpg', $data['full'] );
		$this->assertSame( 'Marie', $data['guest_name'] );
		$this->assertArrayHasKey( 'uploaded_at', $data );
	}

	/**
	 * Test upload rejected when MIME type is not allowed.
	 */
	public function test_upload_rejected_when_mime_type_not_allowed(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => 'gif',
				'type'            => 'image/gif',
				'proper_filename' => false,
			)
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$tmp_file = tempnam( sys_get_temp_dir(), 'egps_test_' );
		file_put_contents( $tmp_file, 'GIF89a' );

		$request = $this->make_request(
			array( 'page_id' => 42 ),
			array(),
			array(
				'photo' => array(
					'name'     => 'animation.gif',
					'type'     => 'image/gif',
					'tmp_name' => $tmp_file,
					'error'    => 0,
					'size'     => 1024,
				),
			)
		);

		$response = REST::handle_photo_upload( $request );

		if ( file_exists( $tmp_file ) ) {
			unlink( $tmp_file );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_file_type', $response->get_error_code() );
		$this->assertSame( 415, $response->get_error_data()['status'] );
	}

	/**
	 * Test upload rejected when file is not a real image.
	 */
	public function test_upload_rejected_when_not_real_image(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => 'jpg',
				'type'            => 'image/jpeg',
				'proper_filename' => false,
			)
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Create a temp file that is NOT a real image (just text).
		$tmp_file = tempnam( sys_get_temp_dir(), 'egps_test_' );
		file_put_contents( $tmp_file, 'This is not an image at all.' );

		$request = $this->make_request(
			array( 'page_id' => 42 ),
			array(),
			array(
				'photo' => array(
					'name'     => 'fake.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp_file,
					'error'    => 0,
					'size'     => 1024,
				),
			)
		);

		$response = REST::handle_photo_upload( $request );

		if ( file_exists( $tmp_file ) ) {
			unlink( $tmp_file );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_image', $response->get_error_code() );
		$this->assertSame( 422, $response->get_error_data()['status'] );
	}

	/**
	 * Test upload rejected when wp_handle_upload fails.
	 */
	public function test_upload_rejected_when_wp_handle_upload_fails(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		Functions\when( 'wp_check_filetype_and_ext' )->justReturn(
			array(
				'ext'             => 'jpg',
				'type'            => 'image/jpeg',
				'proper_filename' => false,
			)
		);
		Functions\when( 'wp_handle_upload' )->justReturn(
			array( 'error' => 'Upload directory is not writable.' )
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Create a real 1x1 pixel JPEG via GD so getimagesize() passes.
		$tmp_file = tempnam( sys_get_temp_dir(), 'egps_test_' ) . '.jpg';
		$img      = imagecreatetruecolor( 1, 1 );
		imagejpeg( $img, $tmp_file );
		imagedestroy( $img );

		$request = $this->make_request(
			array( 'page_id' => 42 ),
			array(),
			array(
				'photo' => array(
					'name'     => 'photo.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp_file,
					'error'    => 0,
					'size'     => 1024,
				),
			)
		);

		$response = REST::handle_photo_upload( $request );

		if ( file_exists( $tmp_file ) ) {
			unlink( $tmp_file );
		}

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_upload_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}

	// ─── §5b: Gallery endpoint — permission checks ───────────────────

	/**
	 * Test gallery rejected when cookie missing.
	 */
	public function test_gallery_rejected_when_cookie_missing(): void {
		$this->stub_valid_page();

		Functions\when( 'apply_filters' )->returnArg( 2 );

		unset( $_COOKIE['egps_42'] );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_gallery_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_cookie', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test gallery rejected when consent is false.
	 */
	public function test_gallery_rejected_when_consent_false(): void {
		$this->stub_valid_page();

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, false );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_gallery_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_no_consent', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test gallery rejected when event_version mismatches.
	 */
	public function test_gallery_rejected_when_event_version_mismatches(): void {
		// Block has eventVersion=2, cookie has event_version=1.
		$this->stub_valid_page( 42, 'correct-password', 2 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_gallery_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_event_version', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// ─── §5b: Gallery endpoint — handler ─────────────────────────────

	/**
	 * Test successful gallery retrieval returns array of photo objects.
	 */
	public function test_gallery_returns_array_of_photo_objects(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		// Create mock posts.
		$post1     = new \stdClass();
		$post1->ID = 100;

		$post2     = new \stdClass();
		$post2->ID = 101;

		$wp_query_mock                 = new \stdClass();
		$wp_query_mock->posts          = array( $post1, $post2 );
		$wp_query_mock->found_posts    = 2;
		$wp_query_mock->max_num_pages  = 1;
		$GLOBALS['egps_wp_query_mock'] = $wp_query_mock;

		Functions\when( 'wp_get_attachment_image_src' )->alias(
			function ( $id, $size = 'thumbnail' ) {
				return array( "https://example.com/photo-{$id}-150x150.jpg", 150, 150, true );
			}
		);
		Functions\when( 'wp_get_attachment_url' )->alias(
			function ( $id ) {
				return "https://example.com/photo-{$id}.jpg";
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				$meta = array(
					'_egps_guest_name'  => 'Marie',
					'_egps_table_name'  => 'Table 3',
					'_egps_uploaded_at' => 1706000000,
				);
				return $meta[ $key ] ?? '';
			}
		);

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'  => 42,
				'per_page' => 30,
				'page'     => 1,
			)
		);

		$response = REST::handle_gallery( $request );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$data = $response->get_data();
		$this->assertCount( 2, $data );

		// Check first photo has all expected fields.
		$photo = $data[0];
		$this->assertSame( 100, $photo['id'] );
		$this->assertStringContainsString( '150x150', $photo['thumbnail'] );
		$this->assertSame( 'https://example.com/photo-100.jpg', $photo['full'] );
		$this->assertSame( 'Marie', $photo['guest_name'] );
		$this->assertSame( 'Table 3', $photo['table_name'] );
		$this->assertSame( 1706000000, $photo['uploaded_at'] );
	}

	/**
	 * Test gallery response includes correct fields.
	 */
	public function test_gallery_response_includes_correct_fields(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$post     = new \stdClass();
		$post->ID = 100;

		$wp_query_mock                 = new \stdClass();
		$wp_query_mock->posts          = array( $post );
		$wp_query_mock->found_posts    = 1;
		$wp_query_mock->max_num_pages  = 1;
		$GLOBALS['egps_wp_query_mock'] = $wp_query_mock;

		Functions\when( 'wp_get_attachment_image_src' )->justReturn(
			array( 'https://example.com/thumb.jpg', 150, 150, true )
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/full.jpg' );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				$meta = array(
					'_egps_guest_name'  => 'Marie',
					'_egps_table_name'  => 'Table 3',
					'_egps_uploaded_at' => 1706000000,
				);
				return $meta[ $key ] ?? '';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request  = $this->make_request( array( 'page_id' => 42 ) );
		$response = REST::handle_gallery( $request );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$data  = $response->get_data();
		$photo = $data[0];

		$expected_keys = array( 'id', 'thumbnail', 'full', 'guest_name', 'table_name', 'uploaded_at' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $photo, "Photo response must include '{$key}'" );
		}
	}

	/**
	 * Test gallery pagination works.
	 */
	public function test_gallery_pagination_works(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$post     = new \stdClass();
		$post->ID = 200;

		$wp_query_mock                 = new \stdClass();
		$wp_query_mock->posts          = array( $post );
		$wp_query_mock->found_posts    = 35;
		$wp_query_mock->max_num_pages  = 2;
		$GLOBALS['egps_wp_query_mock'] = $wp_query_mock;

		Functions\when( 'wp_get_attachment_image_src' )->justReturn(
			array( 'https://example.com/thumb.jpg', 150, 150, true )
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/full.jpg' );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				return match ( $key ) {
					'_egps_guest_name'  => 'Marie',
					'_egps_table_name'  => 'Table 3',
					'_egps_uploaded_at' => 1706000000,
					default             => '',
				};
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'  => 42,
				'per_page' => 30,
				'page'     => 2,
			)
		);

		$response = REST::handle_gallery( $request );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$headers = $response->get_headers();
		$this->assertSame( 35, $headers['X-WP-Total'] );
		$this->assertSame( 2, $headers['X-WP-TotalPages'] );
	}

	/**
	 * Test gallery response headers include X-WP-Total and X-WP-TotalPages.
	 */
	public function test_gallery_response_headers(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$wp_query_mock                 = new \stdClass();
		$wp_query_mock->posts          = array();
		$wp_query_mock->found_posts    = 0;
		$wp_query_mock->max_num_pages  = 0;
		$GLOBALS['egps_wp_query_mock'] = $wp_query_mock;

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request  = $this->make_request( array( 'page_id' => 42 ) );
		$response = REST::handle_gallery( $request );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
	}

	/**
	 * Test gallery 'since' parameter returns only newer photos.
	 */
	public function test_gallery_since_parameter_filters_newer_photos(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		// Only one photo newer than 'since'.
		$post     = new \stdClass();
		$post->ID = 300;

		$wp_query_mock                 = new \stdClass();
		$wp_query_mock->posts          = array( $post );
		$wp_query_mock->found_posts    = 1;
		$wp_query_mock->max_num_pages  = 1;
		$GLOBALS['egps_wp_query_mock'] = $wp_query_mock;

		// Capture WP_Query args to verify 'since' was applied.
		$captured_query_args           = null;
		$GLOBALS['egps_wp_query_args_capture'] = &$captured_query_args;

		Functions\when( 'wp_get_attachment_image_src' )->justReturn(
			array( 'https://example.com/thumb.jpg', 150, 150, true )
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/full.jpg' );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				return match ( $key ) {
					'_egps_guest_name'  => 'Marie',
					'_egps_table_name'  => 'Table 3',
					'_egps_uploaded_at' => 1706001000,
					default             => '',
				};
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, ...$args ) use ( &$captured_query_args ) {
				if ( 'egps_gallery_query_args' === $filter ) {
					$captured_query_args = $args[0];
				}
				return $args[0];
			}
		);

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'since'   => 1706000000,
			)
		);

		$response = REST::handle_gallery( $request );

		unset( $GLOBALS['egps_wp_query_mock'] );
		unset( $GLOBALS['egps_wp_query_args_capture'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertCount( 1, $data );

		// Verify the query args include a meta_query for 'since'.
		$this->assertNotNull( $captured_query_args, 'Gallery query args must be passed through the filter.' );
		$meta_query = $captured_query_args['meta_query'];

		// Find the 'since' clause.
		$since_clause = null;
		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) && '_egps_uploaded_at' === $clause['key'] && isset( $clause['compare'] ) && '>' === $clause['compare'] ) {
				$since_clause = $clause;
				break;
			}
		}
		$this->assertNotNull( $since_clause, 'Meta query must include a since clause for _egps_uploaded_at.' );
		$this->assertSame( 1706000000, $since_clause['value'] );
	}

	/**
	 * Test gallery excludes photos with _egps_requires_moderation = true.
	 */
	public function test_gallery_excludes_moderated_photos(): void {
		$this->stub_valid_page();

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$wp_query_mock                 = new \stdClass();
		$wp_query_mock->posts          = array();
		$wp_query_mock->found_posts    = 0;
		$wp_query_mock->max_num_pages  = 0;
		$GLOBALS['egps_wp_query_mock'] = $wp_query_mock;

		// Capture query args to verify moderation exclusion.
		$captured_query_args = null;
		Functions\when( 'apply_filters' )->alias(
			function ( $filter, ...$args ) use ( &$captured_query_args ) {
				if ( 'egps_gallery_query_args' === $filter ) {
					$captured_query_args = $args[0];
				}
				return $args[0];
			}
		);

		$request  = $this->make_request( array( 'page_id' => 42 ) );
		$response = REST::handle_gallery( $request );

		unset( $GLOBALS['egps_wp_query_mock'] );

		$this->assertNotNull( $captured_query_args );
		$meta_query = $captured_query_args['meta_query'];

		// Find the moderation exclusion clause (may be nested in an OR group).
		$moderation_found = false;
		foreach ( $meta_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			// Check direct clause.
			if ( isset( $clause['key'] ) && '_egps_requires_moderation' === $clause['key'] ) {
				$moderation_found = true;
				break;
			}
			// Check inside OR/AND group.
			foreach ( $clause as $sub_clause ) {
				if ( is_array( $sub_clause ) && isset( $sub_clause['key'] ) && '_egps_requires_moderation' === $sub_clause['key'] ) {
					$moderation_found = true;
					break 2;
				}
			}
		}
		$this->assertTrue( $moderation_found, 'Meta query must exclude moderated photos.' );
	}

	/**
	 * Test upload with date range in the future passes the date check.
	 */
	public function test_upload_date_range_in_future_passes(): void {
		$this->stub_valid_page(
			42,
			'correct-password',
			1,
			array(
				'startDate' => '2026-03-01',
				'endDate'   => '2026-12-31',
			)
		);

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp = null, $timezone = null ) {
				return '2026-03-19';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test upload rejected when before start date.
	 */
	public function test_upload_rejected_when_before_start_date(): void {
		$this->stub_valid_page(
			42,
			'correct-password',
			1,
			array(
				'startDate' => '2027-01-01',
				'endDate'   => '2027-12-31',
			)
		);

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp = null, $timezone = null ) {
				return '2026-03-19';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1, true );
		$this->set_cookie( $payload );

		$request = $this->make_request( array( 'page_id' => 42 ) );

		$result = REST::check_photo_upload_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_event_expired', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
