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
	 * Test that register_menu() calls add_management_page with the correct slug and capability.
	 */
	public function test_register_menu_calls_add_management_page(): void {
		$captured_slug       = null;
		$captured_capability = null;

		Functions\expect( 'add_management_page' )
			->once()
			->withArgs(
				function ( $page_title, $menu_title, $capability, $slug, $callback ) use ( &$captured_slug, &$captured_capability ) {
					$captured_slug       = $slug;
					$captured_capability = $capability;
					return true;
				}
			)
			->andReturn( 'tools_page_event-qr-codes' );

		Admin::register_menu();

		$this->assertSame( 'event-qr-codes', $captured_slug );
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
}
