<?php
/**
 * Moderator role and capabilities management.
 *
 * Handles registration and deregistration of the egps_moderator role.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the custom moderator role and its capabilities.
 */
class Moderator {

	/**
	 * Custom role slug for event photo moderators.
	 *
	 * @var string
	 */
	const ROLE = 'egps_moderator';

	/**
	 * Custom capability that grants photo moderation access.
	 *
	 * @var string
	 */
	const CAPABILITY = 'egps_moderate_photos';

	/**
	 * Block name for the event album block.
	 *
	 * Used to locate the block in parsed post content when resolving
	 * per-page moderator assignments.
	 *
	 * @var string
	 */
	const BLOCK_NAME = 'event-guest-photos-sharing/event-album';

	/**
	 * Register the egps_moderator role with minimal capabilities.
	 *
	 * Called on plugin activation. The role grants only `read` (required for
	 * authenticated frontend access) and the custom `egps_moderate_photos`
	 * capability used by the moderation REST endpoint.
	 */
	public static function register_role(): void {
		add_role(
			self::ROLE,
			'Event Photo Moderator',
			array(
				'read'           => true,
				self::CAPABILITY => true,
			)
		);
	}

	/**
	 * Remove the egps_moderator role.
	 *
	 * Called on plugin deactivation. Users who had this role will retain
	 * their accounts but lose the role assignment.
	 */
	public static function unregister_role(): void {
		remove_role( self::ROLE );
	}

	/**
	 * Register hooks that lock moderator-only users out of the dashboard.
	 *
	 * Moderators are meant to operate entirely on the front end. They have
	 * no reason to access wp-admin, so we block dashboard access, redirect
	 * them to the home page after login, and hide the admin bar.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'block_dashboard_access' ) );
		add_filter( 'login_redirect', array( self::class, 'redirect_after_login' ), 10, 3 );
		add_filter( 'show_admin_bar', array( self::class, 'hide_admin_bar' ) );
	}

	/**
	 * Redirect moderator-only users away from wp-admin.
	 *
	 * Users whose sole role is egps_moderator have no business in the
	 * dashboard. This fires on admin_init and sends them to the site's
	 * home URL. AJAX and REST API requests are excluded so the moderation
	 * endpoints remain functional.
	 */
	public static function block_dashboard_access(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! self::is_moderator_only( $user ) ) {
			return;
		}

		wp_safe_redirect( home_url() );
		exit;
	}

	/**
	 * Redirect moderator-only users to the home page after login.
	 *
	 * By default WordPress sends users to wp-admin after login. Since
	 * moderator-only users are locked out of the dashboard, we redirect
	 * them to the front end instead, avoiding a confusing bounce.
	 *
	 * @param string $redirect_to           The default redirect destination.
	 * @param string $requested_redirect_to The originally requested redirect URL.
	 * @param object $user                  The authenticated user object.
	 *
	 * @return string The filtered redirect URL.
	 */
	public static function redirect_after_login( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( self::is_moderator_only( $user ) ) {
			return home_url();
		}

		return $redirect_to;
	}

	/**
	 * Hide the admin bar for moderator-only users.
	 *
	 * The admin bar is irrelevant for users who cannot access wp-admin.
	 * Hiding it keeps the front-end experience clean and avoids confusion.
	 *
	 * @param bool $show Whether the admin bar should be shown.
	 *
	 * @return bool False for moderator-only users, the original value otherwise.
	 */
	public static function hide_admin_bar( bool $show ): bool {
		$user = wp_get_current_user();

		if ( self::is_moderator_only( $user ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Check whether a user is an assigned moderator for a specific page.
	 *
	 * Each event-album block stores a `moderators` attribute listing the
	 * user IDs that may moderate photos on that page. This method combines
	 * a capability gate (the user must hold `egps_moderate_photos` or
	 * `manage_options`) with a per-page assignment check (the user's ID
	 * must appear in the block's moderators array).
	 *
	 * @param int $user_id The user ID to check.
	 * @param int $page_id The page ID containing the event-album block.
	 *
	 * @return bool True if the user is a moderator for the given page.
	 */
	public static function is_moderator_for_page( int $user_id, int $page_id ): bool {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$content = get_post_field( 'post_content', $page_id );
		$blocks  = parse_blocks( $content );
		$attrs   = self::find_block_attrs( $blocks );

		if ( null === $attrs ) {
			return false;
		}

		$moderators = $attrs['moderators'] ?? array();

		return in_array( $user_id, $moderators, true );
	}

	/**
	 * Recursively search parsed blocks for the event-album block.
	 *
	 * Walks the block tree depth-first to find the first instance of the
	 * event-album block and returns its attributes. This is needed because
	 * the block may be nested inside a Group, Column, or other wrapper block.
	 *
	 * @param array $blocks Array of parsed block arrays from parse_blocks().
	 *
	 * @return array|null The block's attributes array, or null if the block was not found.
	 */
	private static function find_block_attrs( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				return $block['attrs'] ?? array();
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_block_attrs( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Check whether a user's only role is egps_moderator.
	 *
	 * Users who hold additional roles (e.g., administrator) alongside
	 * egps_moderator should retain full dashboard access. This helper
	 * ensures lockout only applies to single-role moderators.
	 *
	 * @param object $user The user object to check.
	 *
	 * @return bool True if the user has exactly one role and it is egps_moderator.
	 */
	private static function is_moderator_only( object $user ): bool {
		$roles = (array) $user->roles;

		return count( $roles ) === 1 && in_array( self::ROLE, $roles, true );
	}
}
