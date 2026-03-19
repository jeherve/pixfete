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
	 *
	 * @filter egps_allowed_mime_types
	 * @since  1.0.0
	 * @param  string[] $mime_types Allowed MIME types.
	 */
	public function get_allowed_mime_types(): array {
		$mime_types = array(
			'image/jpeg',
			'image/png',
			'image/webp',
		);

		if ( $this->server_supports_heic() ) {
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
	public function is_valid_image_type( string $mime_type ): bool {
		return in_array( $mime_type, $this->get_allowed_mime_types(), true );
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
	private function server_supports_heic(): bool {
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
