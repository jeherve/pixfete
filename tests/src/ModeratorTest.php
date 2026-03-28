<?php
/**
 * Tests for the Moderator class.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Jeherve\Event_Guest_Photos_Sharing\Moderator;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for the Moderator class.
 *
 * Covers role registration and deregistration.
 */
class ModeratorTest extends TestCase {
	/**
	 * Set up Brain\Monkey for each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test that register_role calls add_role with the correct arguments.
	 *
	 * Verifies the role slug, display name, and capability array passed
	 * to WordPress's add_role() during plugin activation.
	 */
	public function testRegisterRoleCallsAddRole(): void {
		$called_with = null;

		Functions\expect( 'add_role' )
			->once()
			->with(
				'egps_moderator',
				'Event Photo Moderator',
				\Mockery::on(
					function ( $caps ) use ( &$called_with ) {
						$called_with = $caps;
						return true;
					}
				)
			);

		Moderator::register_role();

		$this->assertSame(
			array(
				'read'                 => true,
				'egps_moderate_photos' => true,
			),
			$called_with
		);
	}

	/**
	 * Test that unregister_role calls remove_role with the correct role slug.
	 *
	 * Verifies the exact role slug passed to WordPress's remove_role()
	 * during plugin deactivation.
	 */
	public function testUnregisterRoleCallsRemoveRole(): void {
		$removed_role = null;

		Functions\expect( 'remove_role' )
			->once()
			->with(
				\Mockery::on(
					function ( $slug ) use ( &$removed_role ) {
						$removed_role = $slug;
						return true;
					}
				)
			);

		Moderator::unregister_role();

		$this->assertSame( 'egps_moderator', $removed_role );
	}
}
