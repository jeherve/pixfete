<?php
/**
 * Plugin Name: Event Guest Photos Sharing
 * Plugin URI: https://herve.bz/my-plugins/event-guest-photos-sharing/
 * Description: Allow your guests to share their photos of your event in a shared photo album, and display those photos live!
 * Author: Jeremy Herve
 * Version: 1.0.0-alpha
 * Author URI: https://herve.bzh/
 * License: GPL-2.0-or-later
 * Text Domain: event-guest-photos-sharing
 * Requires at least: 6.9
 * Requires PHP: 8.3
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

define( 'EGPS_VERSION', '1.0.0-alpha' );
define( 'EGPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EGPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EGPS_PLUGIN_DIR . 'src/class-cookie.php';
require_once EGPS_PLUGIN_DIR . 'src/class-upload.php';
require_once EGPS_PLUGIN_DIR . 'src/class-rest.php';
require_once EGPS_PLUGIN_DIR . 'src/class-block.php';

add_action( 'init', array( Block::class, 'register' ) );
add_action( 'rest_api_init', array( REST::class, 'register_routes' ) );
