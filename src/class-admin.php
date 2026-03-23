<?php
/**
 * Admin class — registers the Tools > Event QR Codes page.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

use Jeherve\Event_Guest_Photos_Sharing\REST;

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

	/**
	 * Enqueue the admin script and pass page data when on the plugin's admin page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public static function enqueue_scripts( string $hook_suffix ): void {
		if ( 'tools_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = EGPS_PLUGIN_DIR . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => EGPS_VERSION,
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			EGPS_PLUGIN_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			EGPS_PLUGIN_URL . 'build/style-admin.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'egpsQrAdmin',
			array( 'pages' => self::get_event_pages() )
		);
	}

	/**
	 * Get the logo as a base64-encoded data URL for a given page.
	 *
	 * Uses the page's featured image, falling back to the site icon.
	 *
	 * @param int $page_id The page ID.
	 * @return string|null A data URL string, or null if no image is available.
	 */
	public static function get_logo_data_url( int $page_id ): ?string {
		$image_url = get_the_post_thumbnail_url( $page_id, 'thumbnail' );

		if ( ! $image_url ) {
			$image_url = get_site_icon_url();
		}

		if ( ! $image_url ) {
			return null;
		}

		return self::image_url_to_data_url( $image_url );
	}

	/**
	 * Convert an image URL to a base64-encoded data URL.
	 *
	 * Reads the file from the local filesystem when possible to avoid remote HTTP
	 * requests for images hosted on this server.
	 *
	 * @param string $url The image URL or local path.
	 * @return string|null A data URL string, or null on failure.
	 */
	private static function image_url_to_data_url( string $url ): ?string {
		// Convert URL to local file path if it's on this server.
		$upload_dir = wp_get_upload_dir();
		$local_path = null;

		if ( ! empty( $upload_dir['baseurl'] ) && str_starts_with( $url, $upload_dir['baseurl'] ) ) {
			$local_path = $upload_dir['basedir'] . substr( $url, strlen( $upload_dir['baseurl'] ) );
		} elseif ( str_starts_with( $url, '/' ) && file_exists( $url ) ) {
			$local_path = $url;
		}

		if ( $local_path && file_exists( $local_path ) ) {
			$contents = file_get_contents( $local_path );
		} else {
			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				return null;
			}
			$contents = wp_remote_retrieve_body( $response );
		}

		if ( empty( $contents ) ) {
			return null;
		}

		$filetype = wp_check_filetype( $url );
		$mime     = $filetype['type'] ?? 'image/png';
		return 'data:' . $mime . ';base64,' . base64_encode( $contents );
	}

	/**
	 * Get all published pages that contain the event block and their attributes.
	 *
	 * @return array<int, array<string, mixed>> Array of event page data.
	 */
	private static function get_event_pages(): array {
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		$event_pages = array();
		foreach ( $pages as $page ) {
			$attrs = REST::get_block_attributes( $page->ID );
			if ( null === $attrs ) {
				continue;
			}

			$event_pages[] = array(
				'id'               => $page->ID,
				'title'            => get_the_title( $page->ID ),
				'slug'             => $page->post_name,
				'permalink'        => get_permalink( $page->ID ),
				'password'         => $attrs['password'] ?? '',
				'enableTableNames' => $attrs['enableTableNames'] ?? false,
				'logoDataUrl'      => self::get_logo_data_url( $page->ID ),
			);
		}

		return $event_pages;
	}
}
