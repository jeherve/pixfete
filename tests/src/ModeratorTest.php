<?php
/**
 * Tests for the Moderator class.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Jeherve\Pixfete\Moderator;
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
				'pixfete_moderator',
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
				'pixfete_moderate_photos' => true,
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

		$this->assertSame( 'pixfete_moderator', $removed_role );
	}

	/**
	 * Test that init() registers the dashboard lockout hooks.
	 *
	 * Verifies that admin_init, login_redirect, and show_admin_bar hooks
	 * are registered so moderator-only users are kept out of wp-admin.
	 */
	public function testInitHooksDashboardLockout(): void {
		Actions\expectAdded( 'admin_init' )
			->once()
			->with( array( Moderator::class, 'block_dashboard_access' ) );

		Filters\expectAdded( 'login_redirect' )
			->once()
			->with( array( Moderator::class, 'redirect_after_login' ), 10, 3 );

		Filters\expectAdded( 'show_admin_bar' )
			->once()
			->with( array( Moderator::class, 'hide_admin_bar' ) );

		Moderator::init();

		// Brain\Monkey expectations are verified in tearDown; add an
		// explicit assertion so PHPUnit does not flag the test as risky.
		$this->assertTrue( true );
	}

	/**
	 * Test that block_dashboard_access redirects a moderator-only user.
	 *
	 * When a user's sole role is pixfete_moderator, they should be redirected
	 * away from wp-admin to the site's home URL.
	 */
	public function testBlockDashboardAccessRedirectsModeratorOnly(): void {
		$user = (object) array( 'roles' => array( 'pixfete_moderator' ) );

		Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( $user );

		Functions\expect( 'wp_doing_ajax' )
			->once()
			->andReturn( false );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		// wp_safe_redirect throws to prevent the subsequent exit from
		// killing the PHPUnit process. We catch the exception and verify
		// that the redirect was called with the correct URL.
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( 'https://example.com' )
			->andReturnUsing(
				function () {
					throw new \RuntimeException( 'redirect_triggered' );
				}
			);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect_triggered' );

		Moderator::block_dashboard_access();
	}

	/**
	 * Test that block_dashboard_access allows users with multiple roles.
	 *
	 * A user who has administrator plus pixfete_moderator should not be
	 * blocked from wp-admin because they have legitimate admin access.
	 */
	public function testBlockDashboardAccessAllowsMultiRoleUsers(): void {
		$user = (object) array( 'roles' => array( 'administrator', 'pixfete_moderator' ) );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );

		Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( $user );

		Functions\expect( 'wp_safe_redirect' )->never();

		Moderator::block_dashboard_access();

		// Brain\Monkey expectations are verified in tearDown; add an
		// explicit assertion so PHPUnit does not flag the test as risky.
		$this->assertTrue( true );
	}

	/**
	 * Test that redirect_after_login returns home_url for moderator-only users.
	 *
	 * After login, moderator-only users should land on the front end,
	 * not wp-admin, since they have no reason to access the dashboard.
	 */
	public function testRedirectAfterLoginRedirectsModeratorOnly(): void {
		$user = (object) array( 'roles' => array( 'pixfete_moderator' ) );

		Functions\expect( 'home_url' )
			->once()
			->andReturn( 'https://example.com' );

		$result = Moderator::redirect_after_login( '/wp-admin/', '', $user );

		$this->assertSame( 'https://example.com', $result );
	}

	/**
	 * Test that redirect_after_login preserves the default redirect for other roles.
	 *
	 * Administrators and other roles should still land on their intended
	 * post-login destination (usually wp-admin).
	 */
	public function testRedirectAfterLoginPreservesDefaultForOtherRoles(): void {
		$user = (object) array( 'roles' => array( 'administrator' ) );

		$result = Moderator::redirect_after_login( '/wp-admin/', '', $user );

		$this->assertSame( '/wp-admin/', $result );
	}

	/**
	 * Test that hide_admin_bar returns false for moderator-only users.
	 *
	 * The admin bar is irrelevant for moderator-only users since they
	 * cannot access wp-admin. Hiding it keeps the front end clean.
	 */
	public function testHideAdminBarForModeratorOnly(): void {
		$user = (object) array( 'roles' => array( 'pixfete_moderator' ) );

		Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( $user );

		$result = Moderator::hide_admin_bar( true );

		$this->assertFalse( $result );
	}

	/**
	 * Test that hide_admin_bar preserves visibility for multi-role users.
	 *
	 * Users who have pixfete_moderator alongside another role (like administrator)
	 * should still see the admin bar since they have legitimate admin access.
	 */
	public function testHideAdminBarPreservesForMultiRoleUsers(): void {
		$user = (object) array( 'roles' => array( 'administrator', 'pixfete_moderator' ) );

		Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( $user );

		$result = Moderator::hide_admin_bar( true );

		$this->assertTrue( $result );
	}

	/**
	 * Stub get_post_field and parse_blocks to return a block array
	 * containing the event-album block with a given moderators attribute.
	 *
	 * This helper simulates a page whose post_content contains the
	 * event-album block, allowing is_moderator_for_page() to be tested
	 * without a real database or WordPress install.
	 *
	 * @param int   $page_id    The page ID to stub content for.
	 * @param array $moderators Array of user IDs assigned as moderators.
	 */
	private function stub_page_with_moderators( int $page_id, array $moderators ): void {
		$block_content = '<!-- wp:pixfete/event-album -->';

		Functions\expect( 'get_post_field' )
			->once()
			->with( 'post_content', $page_id )
			->andReturn( $block_content );

		Functions\expect( 'parse_blocks' )
			->once()
			->with( $block_content )
			->andReturn(
				array(
					array(
						'blockName'  => 'pixfete/event-album',
						'attrs'      => array( 'moderators' => $moderators ),
						'innerBlocks' => array(),
					),
				)
			);
	}

	/**
	 * Test that a user listed in the moderators attribute is recognised.
	 *
	 * When a user has the pixfete_moderate_photos capability and their ID
	 * appears in the block's moderators array, is_moderator_for_page()
	 * should return true.
	 */
	public function testIsModeratorForPageReturnsTrueForAssignedUser(): void {
		$this->stub_page_with_moderators( 42, array( 5, 10 ) );

		Functions\when( 'current_user_can' )->alias(
			function ( string $cap ) {
				return 'pixfete_moderate_photos' === $cap;
			}
		);

		$this->assertTrue( Moderator::is_moderator_for_page( 5, 42 ) );
	}

	/**
	 * Test that a user NOT listed in the moderators attribute is rejected.
	 *
	 * Even though other moderators exist for the page, a user whose ID
	 * is not in the array should not be treated as a moderator.
	 */
	public function testIsModeratorForPageReturnsFalseForUnassignedUser(): void {
		$this->stub_page_with_moderators( 42, array( 5, 10 ) );

		Functions\when( 'current_user_can' )->alias(
			function ( string $cap ) {
				return 'pixfete_moderate_photos' === $cap;
			}
		);

		$this->assertFalse( Moderator::is_moderator_for_page( 99, 42 ) );
	}

	/**
	 * Test that an admin in the moderators list is recognised via manage_options.
	 *
	 * Administrators who hold manage_options (but not necessarily the custom
	 * pixfete_moderate_photos cap) should still pass the capability gate when
	 * their ID appears in the moderators array.
	 */
	public function testIsModeratorForPageReturnsTrueForAdminInList(): void {
		$this->stub_page_with_moderators( 10, array( 1 ) );

		Functions\when( 'current_user_can' )->alias(
			function ( string $cap ) {
				return 'manage_options' === $cap;
			}
		);

		$this->assertTrue( Moderator::is_moderator_for_page( 1, 10 ) );
	}

	/**
	 * Test that a user in the moderators list but without the required
	 * capability is rejected.
	 *
	 * The capability check is a prerequisite — even if the user's ID
	 * appears in the block attribute, they must hold pixfete_moderate_photos
	 * or manage_options to be considered a moderator.
	 */
	public function testIsModeratorForPageReturnsFalseWithoutCapability(): void {
		Functions\when( 'current_user_can' )->alias(
			function () {
				return false;
			}
		);

		$this->assertFalse( Moderator::is_moderator_for_page( 5, 42 ) );
	}

	/**
	 * Test that an empty moderators array means no one is a moderator.
	 *
	 * When the block has no moderators assigned, even a user with the
	 * correct capability should not be treated as a moderator for that page.
	 */
	public function testIsModeratorForPageReturnsFalseWhenNoModerators(): void {
		$this->stub_page_with_moderators( 42, array() );

		Functions\when( 'current_user_can' )->alias(
			function ( string $cap ) {
				return 'pixfete_moderate_photos' === $cap;
			}
		);

		$this->assertFalse( Moderator::is_moderator_for_page( 5, 42 ) );
	}
}
