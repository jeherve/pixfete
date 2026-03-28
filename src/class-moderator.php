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
}
