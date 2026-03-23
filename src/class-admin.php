<?php
/**
 * Admin class — registers the Tools > Event QR Codes page.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin menu registration and page rendering.
 */
class Admin {

	private const MENU_SLUG     = 'event-qr-codes';
	private const SCRIPT_HANDLE = 'egps-qr-admin';

	/**
	 * Register the page under Tools in the WP admin menu.
	 */
	public static function register_menu(): void {
		add_management_page(
			__( 'Event QR Codes', 'event-guest-photos-sharing' ),
			__( 'Event QR Codes', 'event-guest-photos-sharing' ),
			'manage_options',
			self::MENU_SLUG,
			array( static::class, 'render_page' )
		);
	}

	/**
	 * Render the admin page shell — the React app mounts onto this div.
	 */
	public static function render_page(): void {
		echo '<div class="wrap"><div id="egps-qr-admin"></div></div>';
	}
}
