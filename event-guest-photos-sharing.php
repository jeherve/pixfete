<?php
/**
 * Plugin Name: Event Guest Photos Sharing
 * Plugin URI: https://herve.bz/my-plugins/event-guest-photos-sharing/
 * Description: Allow your guests to share their photos of your event in a shared photo album, and display those photos live!
 * Author: Jeremy Herve
 * Version: 1.0.0
 * Author URI: https://herve.bzh/
 * License: GPL2+
 * Text Domain: event-guest-photos-sharing
 * Requires at least: 6.9
 * Requires PHP: 8.3
 *
 * @package jeherve/event-guest-photos-sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

$event_guest_photos_sharing_autoloader = plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';
if ( is_readable( $event_guest_photos_sharing_autoloader ) ) {
	require $event_guest_photos_sharing_autoloader;
}
