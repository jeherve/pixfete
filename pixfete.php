<?php
/**
 * Plugin Name: Pixfête
 * Plugin URI: https://herve.bz/my-plugins/pixfete/
 * Description: Allow your guests to share their photos of your event in a shared photo album, and display those photos live!
 * Author: Jeremy Herve
 * Version: 1.3.1
 * Author URI: https://herve.bzh/
 * License: GPL-2.0-or-later
 * Text Domain: pixfete
 * Requires at least: 6.9
 * Requires PHP: 8.3
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'PIXFETE_VERSION', '1.3.1' );
define( 'PIXFETE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXFETE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PIXFETE_PLUGIN_DIR . 'src/class-cookie.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-upload.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-rest.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-block.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-admin.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-archive.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-cleanup.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-slideshow.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-moderator.php';
require_once PIXFETE_PLUGIN_DIR . 'src/class-pwa.php';

add_action( 'init', array( \Jeherve\Pixfete\Block::class, 'register' ) );
add_action( 'init', array( \Jeherve\Pixfete\Slideshow::class, 'register' ) );
add_action( 'rest_api_init', array( new \Jeherve\Pixfete\REST(), 'register_routes' ) );
add_action( 'admin_menu', array( \Jeherve\Pixfete\Admin::class, 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( \Jeherve\Pixfete\Admin::class, 'enqueue_scripts' ) );
add_action( \Jeherve\Pixfete\Archive::DAILY_HOOK, array( \Jeherve\Pixfete\Archive::class, 'check_events' ) );
add_action( \Jeherve\Pixfete\Archive::BATCH_HOOK, array( \Jeherve\Pixfete\Archive::class, 'process_batch' ) );

add_action( 'template_redirect', array( \Jeherve\Pixfete\PWA::class, 'maybe_serve' ) );

register_activation_hook( __FILE__, array( \Jeherve\Pixfete\Archive::class, 'schedule_cron' ) );
register_deactivation_hook( __FILE__, array( \Jeherve\Pixfete\Archive::class, 'unschedule_cron' ) );

register_activation_hook( __FILE__, array( \Jeherve\Pixfete\Moderator::class, 'register_role' ) );
register_deactivation_hook( __FILE__, array( \Jeherve\Pixfete\Moderator::class, 'unregister_role' ) );

\Jeherve\Pixfete\Moderator::init();
