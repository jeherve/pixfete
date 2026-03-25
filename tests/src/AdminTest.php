<?php
/**
 * Tests for the Admin class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Admin;
use PHPUnit\Framework\TestCase;

/**
 * Test admin page registration and rendering.
 */
class AdminTest extends TestCase {

	/**
	 * Set up Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub i18n functions.
		Functions\when( '__' )->alias(
			// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Matches WordPress function signature.
			function ( string $text, string $domain = 'default' ): string {
				return $text;
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
	 * Test that register_menu() calls add_options_page with the correct slug and capability.
	 */
	public function test_register_menu_calls_add_options_page(): void {
		$captured_slug       = null;
		$captured_capability = null;

		Functions\expect( 'add_options_page' )
			->once()
			->withArgs(
				function ( $page_title, $menu_title, $capability, $slug, $callback ) use ( &$captured_slug, &$captured_capability ) {
					$captured_slug       = $slug;
					$captured_capability = $capability;
					return true;
				}
			)
			->andReturn( 'settings_page_event-guest-photos-sharing' );

		Admin::register_menu();

		$this->assertSame( 'event-guest-photos-sharing', $captured_slug );
		$this->assertSame( 'manage_options', $captured_capability );
	}

	/**
	 * Test that render_page() outputs a div with the expected mount point id.
	 */
	public function test_render_page_outputs_mount_point(): void {
		ob_start();
		Admin::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="egps-qr-admin"', $output );
		$this->assertStringContainsString( '<div', $output );
	}

	/**
	 * Test that enqueue_scripts() does nothing when called on the wrong page.
	 */
	public function test_enqueue_scripts_skips_wrong_page(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_localize_script' )->never();

		Admin::enqueue_scripts( 'edit.php' );

		// Mockery enforces the ->never() expectations above; this confirms we reached here.
		$this->assertTrue( true );
	}

	/**
	 * Test that get_logo_data_url() returns a data URL from the featured image.
	 */
	public function test_get_logo_data_url_returns_featured_image(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '/tmp/egps-test-logo.png' );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => 'image/png', 'ext' => 'png' ) );

		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		file_put_contents( '/tmp/egps-test-logo.png', $png );

		$result = Admin::get_logo_data_url( 1 );

		$this->assertStringStartsWith( 'data:image/', $result );

		unlink( '/tmp/egps-test-logo.png' );
	}

	/**
	 * Test that get_logo_data_url() falls back to the site icon when no featured image.
	 */
	public function test_get_logo_data_url_falls_back_to_site_icon(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( '/tmp/egps-test-icon.png' );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => 'image/png', 'ext' => 'png' ) );

		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		file_put_contents( '/tmp/egps-test-icon.png', $png );

		$result = Admin::get_logo_data_url( 1 );

		$this->assertStringStartsWith( 'data:image/', $result );

		unlink( '/tmp/egps-test-icon.png' );
	}

	/**
	 * Test that get_logo_data_url() returns null when no image is available.
	 */
	public function test_get_logo_data_url_returns_null_when_no_image(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );

		$result = Admin::get_logo_data_url( 1 );

		$this->assertNull( $result );
	}

	/**
	 * Test that enqueue_scripts() enqueues and localizes the script on the correct page.
	 */
	public function test_enqueue_scripts_runs_on_correct_page(): void {
		// Stub that no pages have the block.
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->withArgs(
				function ( $handle ) {
					return $handle === 'egps-qr-admin';
				}
			);

		Functions\expect( 'wp_enqueue_style' )
			->once()
			->withArgs(
				function ( $handle ) {
					return $handle === 'egps-qr-admin';
				}
			);

		Functions\expect( 'wp_localize_script' )
			->once()
			->withArgs(
				function ( $handle, $object_name, $data ) {
					return $handle === 'egps-qr-admin'
						&& $object_name === 'egpsQrAdmin'
						&& is_array( $data )
						&& array_key_exists( 'pages', $data );
				}
			);

		Admin::enqueue_scripts( 'settings_page_event-guest-photos-sharing' );

		// Mockery enforces the ->once() and ->withArgs() expectations above.
		$this->assertTrue( true );
	}

	/**
	 * Test that page titles with HTML entities are decoded before passing to JavaScript.
	 *
	 * WordPress's get_the_title() returns HTML-encoded strings (e.g. &amp; for &).
	 * When passed to wp_localize_script, these entities must be decoded first,
	 * otherwise the JS UI displays raw entities like "John &amp; Jane&#8217;s Wedding".
	 */
	public function test_page_titles_are_html_decoded(): void {
		$mock_page           = new \stdClass();
		$mock_page->ID       = 42;
		$mock_page->post_name = 'wedding';

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( $mock_page ) );

		// Simulate get_the_title() returning HTML-encoded entities, as WordPress does.
		Functions\expect( 'get_the_title' )
			->once()
			->with( 42 )
			->andReturn( 'John &amp; Jane&#8217;s Wedding' );

		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/wedding' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );

		// Mock REST::get_block_attributes chain to indicate this page has the event block.
		Functions\when( 'get_post_field' )->justReturn( '<!-- wp:jeherve/event-guest-photos-sharing -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'event-guest-photos-sharing/event-album',
						'attrs'     => array( 'password' => 'secret' ),
					),
				)
			);

		$captured_data = null;
		Functions\expect( 'wp_enqueue_script' )->once();
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_localize_script' )
			->once()
			->withArgs(
				function ( $handle, $object_name, $data ) use ( &$captured_data ) {
					$captured_data = $data;
					return true;
				}
			);

		Admin::enqueue_scripts( 'settings_page_event-guest-photos-sharing' );

		$this->assertCount( 1, $captured_data['pages'] );
		$this->assertSame(
			"John & Jane\u{2019}s Wedding",
			$captured_data['pages'][0]['title'],
			'Page title should have HTML entities decoded for JavaScript consumption.'
		);
	}
}
