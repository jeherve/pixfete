<?php
/**
 * Tests for the REST auth endpoint.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\REST;
use PHPUnit\Framework\TestCase;

/**
 * Test the REST auth endpoint for guest registration and consent.
 */
class RestAuthTest extends TestCase {

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
	 * Helper to build a mock WP_REST_Request with body params and headers.
	 *
	 * @param array  $params  Body parameters.
	 * @param array  $headers Headers (key => value).
	 * @return \WP_REST_Request
	 */
	private function make_request( array $params = array(), array $headers = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		return $request;
	}

	/**
	 * Helper to stub page validation for a valid page with our block.
	 *
	 * @param int    $page_id  The page ID.
	 * @param string $password The block password attribute.
	 * @param int    $version  The eventVersion attribute.
	 */
	private function stub_valid_page( int $page_id = 42, string $password = 'correct-password', int $version = 1 ): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'has_block' )->justReturn( true );
		Functions\when( 'get_post_field' )->justReturn(
			'<!-- wp:event-guest-photos-sharing/event-album {"password":"' . $password . '","eventVersion":' . $version . '} -->'
		);
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
					'attrs'     => array(
						'password'     => $password,
						'eventVersion' => $version,
					),
				),
			)
		);
	}

	/**
	 * Helper to stub a valid CSRF nonce transient.
	 *
	 * @param string $token   The nonce token.
	 * @param int    $page_id The page ID the nonce is valid for.
	 */
	private function stub_valid_nonce( string $token = 'valid-nonce-token', int $page_id = 42 ): void {
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( $token, $page_id ) {
				if ( $key === 'egps_csrf_' . $token ) {
					return $page_id;
				}
				return false;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	/**
	 * Helper: build a valid cookie payload with consent=false.
	 *
	 * @param int $page_id Page ID.
	 * @param int $version Event version.
	 * @return array<string, mixed>
	 */
	private function make_cookie_payload( int $page_id = 42, int $version = 1 ): array {
		return array(
			'page_id'       => $page_id,
			'event_version' => $version,
			'guest_name'    => 'Alice',
			'table_name'    => 'Table 5',
			'consent'       => false,
			'registered_at' => 1700000000,
			'expires_at'    => time() + 86400,
		);
	}

	// ─── §4a: Route registration ──────────────────────────────────────

	/**
	 * Test that register_routes calls register_rest_route with the correct path and method.
	 */
	public function test_register_routes_registers_auth_endpoint(): void {
		$captured = null;
		Functions\expect( 'register_rest_route' )
			->once()
			->withArgs(
				function ( $namespace, $route, $args ) use ( &$captured ) {
					$captured = array(
						'namespace' => $namespace,
						'route'     => $route,
						'args'      => $args,
					);
					return true;
				}
			);

		REST::register_routes();

		$this->assertSame( 'event-guest-photos-sharing/v1', $captured['namespace'] );
		$this->assertSame( '/auth/(?P<page_id>\d+)', $captured['route'] );
		$this->assertSame( 'POST', $captured['args']['methods'] );
		$this->assertIsCallable( $captured['args']['callback'] );
	}

	// ─── §4a: action=register ─────────────────────────────────────────

	/**
	 * Test successful registration with correct password returns consent state.
	 */
	public function test_register_success_returns_consent_state(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'new-consent-nonce' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				return $data;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'correct-password',
				'guest_name' => 'Alice',
				'table_name' => 'Table 5',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertIsArray( $response );
		$this->assertSame( 'consent', $response['state'] );
		$this->assertArrayHasKey( 'consent_nonce', $response );
		$this->assertSame( 'new-consent-nonce', $response['consent_nonce'] );
	}

	/**
	 * Test that registration sets the HMAC cookie.
	 */
	public function test_register_sets_hmac_cookie(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'new-consent-nonce' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				return $data;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$GLOBALS['egps_setcookie_last_call'] = null;

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'correct-password',
				'guest_name' => 'Alice',
				'table_name' => 'Table 5',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		REST::handle_auth( $request );

		$call = $GLOBALS['egps_setcookie_last_call'];
		$this->assertNotNull( $call, 'setcookie must have been called.' );
		$this->assertSame( 'egps_42', $call['name'] );
	}

	/**
	 * Test registration with wrong password returns 403 egps_invalid_password.
	 */
	public function test_register_wrong_password_returns_403(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'wrong-password',
				'guest_name' => 'Alice',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_password', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Test registration with honeypot filled returns 403 egps_invalid_password (same error).
	 */
	public function test_register_honeypot_filled_returns_403_same_as_wrong_password(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, ...$args ) {
				if ( 'egps_honeypot_field_name' === $filter ) {
					return 'email';
				}
				return $args[0];
			}
		);

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'correct-password',
				'guest_name' => 'Alice',
				'email'      => 'bot@spam.com', // honeypot filled!
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_password', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Test registration with missing CSRF token returns 403 egps_invalid_nonce.
	 */
	public function test_register_missing_csrf_returns_403(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'correct-password',
				'guest_name' => 'Alice',
			),
			array() // No nonce header.
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_nonce', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Test registration with invalid CSRF token returns 403 egps_invalid_nonce.
	 */
	public function test_register_invalid_csrf_returns_403(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		// Transient for this token does not exist.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'correct-password',
				'guest_name' => 'Alice',
			),
			array( 'X-EGPS-Nonce' => 'bogus-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_nonce', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Test registration with CSRF token for a different page returns 403.
	 */
	public function test_register_csrf_for_wrong_page_returns_403(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		// Nonce transient stores page_id 99, but request is for page 42.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( $key === 'egps_csrf_valid-nonce-token' ) {
					return 99; // Different page.
				}
				return false;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'correct-password',
				'guest_name' => 'Alice',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_nonce', $response->get_error_code() );
	}

	/**
	 * Test registration with missing guest_name returns 400 egps_missing_fields.
	 */
	public function test_register_missing_guest_name_returns_400(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'  => 42,
				'action'   => 'register',
				'password' => 'correct-password',
				// guest_name missing!
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_missing_fields', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Test registration with empty guest_name returns 400 egps_missing_fields.
	 */
	public function test_register_empty_guest_name_returns_400(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'correct-password',
				'guest_name' => '',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_missing_fields', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Test that invalid action returns 400 egps_invalid_action.
	 */
	public function test_invalid_action_returns_400(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'unknown',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_action', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Test request to non-existent page returns 404 egps_invalid_page.
	 */
	public function test_nonexistent_page_returns_404(): void {
		Functions\when( 'get_post_status' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( false );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id' => 999,
				'action'  => 'register',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_page', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Test request to page without our block returns 404 egps_invalid_page.
	 */
	public function test_page_without_block_returns_404(): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'register',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_page', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Test request to draft page returns 404 egps_invalid_page.
	 */
	public function test_draft_page_returns_404(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'has_block' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'register',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_page', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Test request to a post (not page) returns 404 egps_invalid_page.
	 */
	public function test_post_type_not_page_returns_404(): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'has_block' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'register',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_page', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Test registration with password below minimum length returns 403.
	 */
	public function test_register_short_password_in_block_returns_403(): void {
		// Block has a short password (less than 8 chars), but filter overrides min length.
		$this->stub_valid_page( 42, 'short', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, ...$args ) {
				if ( 'egps_password_min_length' === $filter ) {
					return 8; // Default minimum.
				}
				return $args[0];
			}
		);

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'password'   => 'short',
				'guest_name' => 'Alice',
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_password', $response->get_error_code() );
	}

	// ─── §4b: action=consent ──────────────────────────────────────────

	/**
	 * Test consent with valid cookie and nonce returns gallery state.
	 */
	public function test_consent_success_returns_gallery_state(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		// Stub consent nonce.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( $key === 'egps_csrf_consent-nonce-token' ) {
					return 42;
				}
				return false;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				return $data;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Set a valid cookie with consent=false.
		$payload = $this->make_cookie_payload( 42, 1 );
		$_COOKIE['egps_42'] = \Jeherve\Event_Guest_Photos_Sharing\Cookie::sign( $payload );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'consent',
			),
			array( 'X-EGPS-Nonce' => 'consent-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertIsArray( $response );
		$this->assertSame( 'gallery', $response['state'] );
	}

	/**
	 * Test consent updates cookie to consent=true.
	 */
	public function test_consent_updates_cookie_consent_true(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( $key === 'egps_csrf_consent-nonce-token' ) {
					return 42;
				}
				return false;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				return $data;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$payload = $this->make_cookie_payload( 42, 1 );
		$_COOKIE['egps_42'] = \Jeherve\Event_Guest_Photos_Sharing\Cookie::sign( $payload );

		$GLOBALS['egps_setcookie_last_call'] = null;

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'consent',
			),
			array( 'X-EGPS-Nonce' => 'consent-nonce-token' )
		);

		REST::handle_auth( $request );

		$call = $GLOBALS['egps_setcookie_last_call'];
		$this->assertNotNull( $call, 'setcookie must have been called to update consent.' );
		$this->assertSame( 'egps_42', $call['name'] );

		// Verify the new cookie payload has consent=true.
		$parts     = explode( '.', $call['value'] );
		$base64    = strtr( $parts[0], '-_', '+/' );
		$json      = base64_decode( $base64, true );
		$new_payload = json_decode( $json, true );

		$this->assertTrue( $new_payload['consent'] );
	}

	/**
	 * Test consent without a valid cookie returns 403 egps_invalid_cookie.
	 *
	 * Cookie is verified before the CSRF nonce, so no nonce is consumed.
	 */
	public function test_consent_without_cookie_returns_403(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		// No cookie set.
		unset( $_COOKIE['egps_42'] );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'consent',
			),
			array( 'X-EGPS-Nonce' => 'consent-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_cookie', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Test consent with cookie that already has consent=true returns 403.
	 *
	 * Cookie is verified before the CSRF nonce, so no nonce is consumed.
	 */
	public function test_consent_already_consented_returns_403(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Cookie with consent=true.
		$payload              = $this->make_cookie_payload( 42, 1 );
		$payload['consent']   = true;
		$_COOKIE['egps_42']   = \Jeherve\Event_Guest_Photos_Sharing\Cookie::sign( $payload );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'consent',
			),
			array( 'X-EGPS-Nonce' => 'consent-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_cookie', $response->get_error_code() );
	}

	/**
	 * Test consent with wrong event_version returns 403 egps_invalid_event_version.
	 */
	public function test_consent_wrong_event_version_returns_403(): void {
		// Block has eventVersion=2, but cookie has event_version=1.
		$this->stub_valid_page( 42, 'correct-password', 2 );

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				if ( $key === 'egps_csrf_consent-nonce-token' ) {
					return 42;
				}
				return false;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Cookie with event_version=1 (outdated).
		$payload            = $this->make_cookie_payload( 42, 1 );
		$_COOKIE['egps_42'] = \Jeherve\Event_Guest_Photos_Sharing\Cookie::sign( $payload );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'consent',
			),
			array( 'X-EGPS-Nonce' => 'consent-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_event_version', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	/**
	 * Test consent with missing CSRF nonce returns 403 egps_invalid_nonce.
	 *
	 * Cookie is verified first (passes), then the nonce check fails.
	 */
	public function test_consent_missing_nonce_returns_403(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Valid cookie with consent=false so cookie check passes.
		$payload            = $this->make_cookie_payload( 42, 1 );
		$_COOKIE['egps_42'] = \Jeherve\Event_Guest_Photos_Sharing\Cookie::sign( $payload );

		$request = $this->make_request(
			array(
				'page_id' => 42,
				'action'  => 'consent',
			),
			array() // No nonce.
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_invalid_nonce', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	// ─── §4c: validate_page helper ────────────────────────────────────

	/**
	 * Test validate_page returns block attrs for a valid page.
	 */
	public function test_validate_page_returns_attrs_for_valid_page(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );

		$result = REST::validate_page( 42 );

		$this->assertIsArray( $result );
		$this->assertSame( 'correct-password', $result['password'] );
		$this->assertSame( 1, $result['eventVersion'] );
	}

	/**
	 * Test validate_page returns WP_Error for non-published page.
	 */
	public function test_validate_page_returns_error_for_draft(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'has_block' )->justReturn( true );

		$result = REST::validate_page( 42 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_page', $result->get_error_code() );
	}

	/**
	 * Test validate_page returns WP_Error for non-page post type.
	 */
	public function test_validate_page_returns_error_for_non_page(): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'has_block' )->justReturn( true );

		$result = REST::validate_page( 42 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_page', $result->get_error_code() );
	}

	/**
	 * Test validate_page returns WP_Error for page without our block.
	 */
	public function test_validate_page_returns_error_for_page_without_block(): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'has_block' )->justReturn( false );

		$result = REST::validate_page( 42 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'egps_invalid_page', $result->get_error_code() );
	}

	/**
	 * Test get_block_attributes returns attrs when block exists.
	 */
	public function test_get_block_attributes_returns_attrs(): void {
		Functions\when( 'get_post_field' )->justReturn(
			'<!-- wp:event-guest-photos-sharing/event-album {"password":"pw","eventVersion":2} -->'
		);
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'core/paragraph',
					'attrs'     => array(),
				),
				array(
					'blockName' => 'event-guest-photos-sharing/event-album',
					'attrs'     => array(
						'password'     => 'pw',
						'eventVersion' => 2,
					),
				),
			)
		);

		$result = REST::get_block_attributes( 42 );

		$this->assertIsArray( $result );
		$this->assertSame( 'pw', $result['password'] );
		$this->assertSame( 2, $result['eventVersion'] );
	}

	/**
	 * Test get_block_attributes returns null when block not found.
	 */
	public function test_get_block_attributes_returns_null_without_block(): void {
		Functions\when( 'get_post_field' )->justReturn( '<!-- wp:core/paragraph -->' );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'core/paragraph',
					'attrs'     => array(),
				),
			)
		);

		$result = REST::get_block_attributes( 42 );

		$this->assertNull( $result );
	}

	/**
	 * Test get_block_attributes finds the block nested inside a group block.
	 */
	public function test_get_block_attributes_finds_nested_block(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:event-guest-photos-sharing/event-album {"password":"test1234","eventVersion":1} /--></div><!-- /wp:group -->';
		Functions\when( 'get_post_field' )->justReturn( $content );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName'   => 'core/group',
					'attrs'       => array(),
					'innerBlocks' => array(
						array(
							'blockName'   => 'event-guest-photos-sharing/event-album',
							'attrs'       => array(
								'password'     => 'test1234',
								'eventVersion' => 1,
							),
							'innerBlocks' => array(),
						),
					),
				),
			)
		);

		$result = REST::get_block_attributes( 42 );

		$this->assertIsArray( $result );
		$this->assertSame( 'test1234', $result['password'] );
		$this->assertSame( 1, $result['eventVersion'] );
	}

	/**
	 * Test registration with missing password returns 400 egps_missing_fields.
	 */
	public function test_register_missing_password_returns_400(): void {
		$this->stub_valid_page( 42, 'correct-password', 1 );
		$this->stub_valid_nonce( 'valid-nonce-token', 42 );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$request = $this->make_request(
			array(
				'page_id'    => 42,
				'action'     => 'register',
				'guest_name' => 'Alice',
				// password missing!
			),
			array( 'X-EGPS-Nonce' => 'valid-nonce-token' )
		);

		$response = REST::handle_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'egps_missing_fields', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}
}
