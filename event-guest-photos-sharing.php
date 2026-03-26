<?php
/**
 * Plugin Name: Event Guest Photos Sharing
 * Plugin URI: https://herve.bz/my-plugins/event-guest-photos-sharing/
 * Description: Allow your guests to share their photos of your event in a shared photo album, and display those photos live!
 * Author: Jeremy Herve
 * Version: 1.2.0
 * Author URI: https://herve.bzh/
 * License: GPL-2.0-or-later
 * Text Domain: event-guest-photos-sharing
 * Requires at least: 6.9
 * Requires PHP: 8.3
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'EGPS_VERSION', '1.2.0' );
define( 'EGPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EGPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EGPS_PLUGIN_DIR . 'src/class-cookie.php';
require_once EGPS_PLUGIN_DIR . 'src/class-upload.php';
require_once EGPS_PLUGIN_DIR . 'src/class-rest.php';
require_once EGPS_PLUGIN_DIR . 'src/class-block.php';
require_once EGPS_PLUGIN_DIR . 'src/class-admin.php';
require_once EGPS_PLUGIN_DIR . 'src/class-archive.php';
require_once EGPS_PLUGIN_DIR . 'src/class-cleanup.php';

add_action( 'init', array( \Jeherve\Event_Guest_Photos_Sharing\Block::class, 'register' ) );
add_action( 'rest_api_init', array( new \Jeherve\Event_Guest_Photos_Sharing\REST(), 'register_routes' ) );
add_action( 'admin_menu', array( \Jeherve\Event_Guest_Photos_Sharing\Admin::class, 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( \Jeherve\Event_Guest_Photos_Sharing\Admin::class, 'enqueue_scripts' ) );
add_action( \Jeherve\Event_Guest_Photos_Sharing\Archive::DAILY_HOOK, array( \Jeherve\Event_Guest_Photos_Sharing\Archive::class, 'check_events' ) );
add_action( \Jeherve\Event_Guest_Photos_Sharing\Archive::BATCH_HOOK, array( \Jeherve\Event_Guest_Photos_Sharing\Archive::class, 'process_batch' ) );

register_activation_hook( __FILE__, array( \Jeherve\Event_Guest_Photos_Sharing\Archive::class, 'schedule_cron' ) );
register_deactivation_hook( __FILE__, array( \Jeherve\Event_Guest_Photos_Sharing\Archive::class, 'unschedule_cron' ) );
