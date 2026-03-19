<?php
/**
 * Photo upload validation and attachment creation with guest metadata.
 *
 * Handles MIME type validation (including HEIC detection based on server
 * capabilities) and creates WordPress attachments with guest-specific
 * post meta for tracking and moderation.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

/**
 * Photo upload validation and WordPress attachment creation.
 */
class Upload {

	/**
	 * Get the list of allowed MIME types for photo uploads.
	 *
	 * Returns the base list (jpeg, png, webp) plus HEIC/HEIF if the server
	 * supports them. The final list is filterable.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Array of allowed MIME type strings.
	 */
	public static function get_allowed_mime_types(): array {
		$mime_types = array(
			'image/jpeg',
			'image/png',
			'image/webp',
		);

		if ( self::server_supports_heic() ) {
			$mime_types[] = 'image/heic';
			$mime_types[] = 'image/heif';
		}

		/**
		 * Filters the list of allowed MIME types for photo uploads.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $mime_types Allowed MIME types.
		 */
		return (array) apply_filters( 'egps_allowed_mime_types', $mime_types );
	}

	/**
	 * Check if a MIME type is in the allowed list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mime_type The MIME type to validate.
	 * @return bool True if the MIME type is allowed, false otherwise.
	 */
	public static function is_valid_image_type( string $mime_type ): bool {
		return in_array( $mime_type, self::get_allowed_mime_types(), true );
	}

	/**
	 * Create a WordPress attachment for an uploaded photo with guest metadata.
	 *
	 * Inserts the attachment post, generates its metadata, and stores
	 * guest-specific post meta for tracking and optional moderation.
	 * Returns 0 on failure (e.g. if wp_insert_attachment() returns a WP_Error).
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path  Absolute path to the uploaded file.
	 * @param string $filename   Original filename from the upload.
	 * @param string $mime_type  MIME type of the uploaded file.
	 * @param int    $page_id    The event page ID (becomes post_parent).
	 * @param array  $guest_data {
	 *     Guest identification data.
	 *
	 *     @type string $guest_name Pre-sanitized display name of the guest.
	 *     @type string $table_name Pre-sanitized table/group name.
	 *     @type string $guest_id   Pre-computed hash identifying the guest.
	 * }
	 * @return int The newly created attachment ID, or 0 on failure.
	 */
	public static function create_attachment( string $file_path, string $filename, string $mime_type, int $page_id, array $guest_data ): int {
		$attachment_args = array(
			'post_mime_type' => $mime_type,
			'post_title'     => sanitize_file_name( $filename ),
			'post_status'    => 'inherit',
			'post_parent'    => $page_id,
		);

		$attachment_id = wp_insert_attachment( $attachment_args, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Store guest meta, sanitizing user-provided strings.
		update_post_meta( $attachment_id, '_egps_guest_name', sanitize_text_field( $guest_data['guest_name'] ) );
		update_post_meta( $attachment_id, '_egps_table_name', sanitize_text_field( $guest_data['table_name'] ) );
		update_post_meta( $attachment_id, '_egps_guest_id', sanitize_key( $guest_data['guest_id'] ) );
		update_post_meta( $attachment_id, '_egps_uploaded_at', time() );

		/**
		 * Filters whether a newly uploaded photo requires moderation.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $requires_moderation Whether the photo requires moderation. Default false.
		 * @param int  $attachment_id       The attachment post ID.
		 * @param int  $page_id             The event page ID.
		 */
		$requires_moderation = (bool) apply_filters( 'egps_photo_requires_moderation', false, $attachment_id, $page_id );
		update_post_meta( $attachment_id, '_egps_requires_moderation', $requires_moderation );

		/**
		 * Fires after a guest photo has been uploaded and its metadata stored.
		 *
		 * @since 1.0.0
		 *
		 * @param int $attachment_id The attachment post ID.
		 * @param int $page_id       The event page ID.
		 */
		do_action( 'egps_after_photo_upload', $attachment_id, $page_id );

		return $attachment_id;
	}

	/**
	 * Check whether the server supports HEIC image files.
	 *
	 * Uses wp_check_filetype_and_ext() to determine support, and caches
	 * the result in a transient for one day to avoid repeated checks.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the server supports HEIC files.
	 */
	private static function server_supports_heic(): bool {
		$cached = get_transient( 'egps_heic_support' );

		if ( false !== $cached ) {
			return 'supported' === $cached;
		}

		$check = wp_check_filetype_and_ext( 'test.heic', 'test.heic', null, array( 'heic' => 'image/heic' ) );

		$supported = ! empty( $check['ext'] ) && ! empty( $check['type'] );
		set_transient( 'egps_heic_support', $supported ? 'supported' : 'unsupported', DAY_IN_SECONDS );

		return $supported;
	}
}
