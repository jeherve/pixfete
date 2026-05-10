<?php
/**
 * Tests for the REST /token endpoint.
 *
 * The /token endpoint replaces the practice of embedding a CSRF token
 * in rendered block HTML, which would silently break whenever the
 * page response was cached: every visitor would receive (and burn)
 * the same one-time-use token.
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
 * Test the REST /token endpoint that issues fresh CSRF tokens.
 */
class RestTokenTest extends TestCase {

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
		Functions\when( 'rest_ensure_response' )->alias(
			function ( $data ) {
				if ( $data instanceof WP_REST_Response ) {
					return $data;
				}
				return new WP_REST_Response( $data );
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
	 * Stub a valid page that contains the event-album block.
	 */
	private function stub_valid_page( int $page_id = 42 ): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'has_block' )->justReturn( true );
		Functions\when( 'get_post_field' )->justReturn(
			'<!-- wp:pixfete/event-album {"password":"x"} -->'
		);
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName' => 'pixfete/event-album',
					'attrs'     => array( 'password' => 'x' ),
				),
			)
		);
	}

	/**
	 * Test that the /token route is registered on GET.
	 */
	public function test_register_routes_registers_token_endpoint(): void {
		$captured = array();
		Functions\expect( 'register_rest_route' )
			->atLeast()
			->times( 1 )
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

		$matches = array_values(
			array_filter(
				$captured,
				function ( $row ) {
					return $row['route'] === '/token/(?P<page_id>\d+)';
				}
			)
		);

		$this->assertCount( 1, $matches, 'Token route must be registered exactly once.' );
		$this->assertSame( 'pixfete/v1', $matches[0]['namespace'] );
		$this->assertSame( 'GET', $matches[0]['args']['methods'] );
		$this->assertIsCallable( $matches[0]['args']['callback'] );
	}

	/**
	 * Happy path: the endpoint returns a 32-char nonce and stores it as a
	 * transient bound to the page ID.
	 */
	public function test_handle_token_returns_nonce_and_writes_transient(): void {
		$this->stub_valid_page( 42 );

		$captured_set = null;
		Functions\when( 'wp_generate_password' )->justReturn( 'minted-token' );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$captured_set ) {
				$captured_set = compact( 'key', 'value', 'ttl' );
				return true;
			}
		);

		$request = new WP_REST_Request();
		$request->set_param( 'page_id', 42 );

		$response = REST::handle_token( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'minted-token', $data['nonce'] );

		$this->assertNotNull( $captured_set, 'set_transient must be called.' );
		$this->assertSame( 'pixfete_csrf_minted-token', $captured_set['key'] );
		$this->assertSame( 42, $captured_set['value'] );
		$this->assertSame( HOUR_IN_SECONDS, $captured_set['ttl'] );
	}

	/**
	 * Pages that don't exist or don't carry the block must not be a free
	 * channel for spamming the transient store.
	 */
	public function test_handle_token_rejects_unknown_page(): void {
		Functions\when( 'get_post_status' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( false );

		// Should never be reached if validate_page short-circuits.
		Functions\expect( 'wp_generate_password' )->never();
		Functions\expect( 'set_transient' )->never();

		$request = new WP_REST_Request();
		$request->set_param( 'page_id', 9999 );

		$response = REST::handle_token( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'pixfete_invalid_page', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}
}
