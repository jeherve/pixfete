<?php
/**
 * Tests for the Admin class.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Pixfete\Admin;
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
			->andReturn( 'settings_page_pixfete' );

		Admin::register_menu();

		$this->assertSame( 'pixfete', $captured_slug );
		$this->assertSame( 'manage_options', $captured_capability );
	}

	/**
	 * Test that render_page() outputs a div with the expected mount point id.
	 */
	public function test_render_page_outputs_mount_point(): void {
		ob_start();
		Admin::render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="pixfete-qr-admin"', $output );
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
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '/tmp/pixfete-test-logo.png' );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => 'image/png', 'ext' => 'png' ) );

		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		file_put_contents( '/tmp/pixfete-test-logo.png', $png );

		$result = Admin::get_logo_data_url( 1 );

		$this->assertStringStartsWith( 'data:image/', $result );

		unlink( '/tmp/pixfete-test-logo.png' );
	}

	/**
	 * Test that get_logo_data_url() falls back to the site icon when no featured image.
	 */
	public function test_get_logo_data_url_falls_back_to_site_icon(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( '/tmp/pixfete-test-icon.png' );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => 'image/png', 'ext' => 'png' ) );

		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		file_put_contents( '/tmp/pixfete-test-icon.png', $png );

		$result = Admin::get_logo_data_url( 1 );

		$this->assertStringStartsWith( 'data:image/', $result );

		unlink( '/tmp/pixfete-test-icon.png' );
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
					return $handle === 'pixfete-qr-admin';
				}
			);

		Functions\expect( 'wp_enqueue_style' )
			->once()
			->withArgs(
				function ( $handle ) {
					return $handle === 'pixfete-qr-admin';
				}
			);

		Functions\expect( 'wp_localize_script' )
			->once()
			->withArgs(
				function ( $handle, $object_name, $data ) {
					return $handle === 'pixfete-qr-admin'
						&& $object_name === 'pixfeteQrAdmin'
						&& is_array( $data )
						&& array_key_exists( 'pages', $data );
				}
			);

		Admin::enqueue_scripts( 'settings_page_pixfete' );

		// Mockery enforces the ->once() and ->withArgs() expectations above.
		$this->assertTrue( true );
	}

	/**
	 * Test that enqueue_scripts includes archive data for each event page.
	 */
	public function test_enqueue_scripts_includes_archive_data(): void {
		$mock_page           = new \stdClass();
		$mock_page->ID       = 42;
		$mock_page->post_name = 'wedding';

		Functions\expect( 'get_posts' )->once()->andReturn( array( $mock_page ) );
		Functions\expect( 'get_the_title' )->once()->with( 42 )->andReturn( 'Wedding' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/wedding' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( '<!-- wp:pixfete/event-album -->' );
		Functions\expect( 'parse_blocks' )->once()->andReturn(
			array(
				array(
					'blockName' => 'pixfete/event-album',
					'attrs'     => array(
						'password'     => 'secret',
						'dateRangeEnd' => '2026-01-01',
					),
				),
			)
		);

		// Archive data for this page.
		Functions\expect( 'get_option' )
			->with( 'pixfete_zip_archives', array() )
			->andReturn(
				array(
					42 => array(
						'status'     => 'complete',
						'url'        => 'https://example.com/uploads/pixfete-archives/pixfete-archive-42-abc123.zip',
						'created_at' => 1742900000,
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

		Admin::enqueue_scripts( 'settings_page_pixfete' );

		$this->assertArrayHasKey( 'archive', $captured_data['pages'][0] );
		$this->assertSame( 'complete', $captured_data['pages'][0]['archive']['status'] );
		$this->assertSame(
			'https://example.com/uploads/pixfete-archives/pixfete-archive-42-abc123.zip',
			$captured_data['pages'][0]['archive']['url']
		);
		$this->assertArrayHasKey( 'dateRangeEnd', $captured_data['pages'][0] );
		$this->assertSame( '2026-01-01', $captured_data['pages'][0]['dateRangeEnd'] );
	}

	/**
	 * Test that get_logo_data_url() detects MIME from content when wp_check_filetype()
	 * returns an empty type string (e.g. for extensionless URLs or unrecognised file types).
	 *
	 * Without this detection, the data URL is generated as "data:;base64,…" (missing MIME type),
	 * which prevents the QR code library from rendering the logo image.
	 */
	public function test_get_logo_data_url_detects_png_mime_from_content(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '/tmp/pixfete-test-noext-png' );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => '', 'ext' => '' ) );

		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		file_put_contents( '/tmp/pixfete-test-noext-png', $png );

		$result = Admin::get_logo_data_url( 1 );

		$this->assertStringStartsWith( 'data:image/png;base64,', $result );

		unlink( '/tmp/pixfete-test-noext-png' );
	}

	/**
	 * Test that get_logo_data_url() detects SVG content and uses the correct MIME type
	 * when wp_check_filetype() cannot determine it from the URL.
	 *
	 * WordPress blocks SVG in its allowed MIME types by default, so wp_check_filetype()
	 * returns an empty type for SVG files. Without content-based detection, the data URL
	 * would use image/png for SVG content, causing the browser to fail loading the image.
	 */
	public function test_get_logo_data_url_detects_svg_mime_from_content(): void {
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( '/tmp/pixfete-test-noext-svg' );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => '', 'basedir' => '' ) );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'type' => '', 'ext' => '' ) );

		$svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
		file_put_contents( '/tmp/pixfete-test-noext-svg', $svg );

		$result = Admin::get_logo_data_url( 1 );

		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $result );

		unlink( '/tmp/pixfete-test-noext-svg' );
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
		Functions\when( 'get_post_field' )->justReturn( '<!-- wp:jeherve/pixfete -->' );
		Functions\expect( 'parse_blocks' )
			->once()
			->andReturn(
				array(
					array(
						'blockName' => 'pixfete/event-album',
						'attrs'     => array( 'password' => 'secret' ),
					),
				)
			);

		// Stub the get_option call introduced by Archive::get_archive().
		Functions\when( 'get_option' )->justReturn( array() );

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

		Admin::enqueue_scripts( 'settings_page_pixfete' );

		$this->assertCount( 1, $captured_data['pages'] );
		$this->assertSame(
			"John & Jane\u{2019}s Wedding",
			$captured_data['pages'][0]['title'],
			'Page title should have HTML entities decoded for JavaScript consumption.'
		);
	}
}
