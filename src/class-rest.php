<?php
/**
 * Register REST API endpoints for guest authentication, photo upload,
 * gallery retrieval, photo moderation, and event cleanup.
 *
 * Handles the two-step auth flow: registration (password validation,
 * cookie creation) and consent (cookie update), as well as the
 * one-step slideshow auth flow (password validation with consent
 * pre-granted). Also handles photo
 * uploads with MIME/image validation, gallery retrieval with
 * pagination, and event cleanup for removing all uploaded photos.
 * Uses CSRF tokens stored as transients and HMAC-signed
 * cookies for stateless guest auth.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

use WP_Error;
use WP_Query;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for guest authentication, photo upload, gallery, moderation, and event cleanup endpoints.
 */
class REST extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'pixfete/v1';

	/**
	 * Block name for the event album.
	 *
	 * @var string
	 */
	private const BLOCK_NAME = 'pixfete/event-album';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/auth/(?P<page_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( static::class, 'handle_auth' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/photos/(?P<page_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( static::class, 'handle_photo_upload' ),
				'permission_callback' => array( static::class, 'check_photo_upload_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/photos/(?P<page_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( static::class, 'handle_gallery' ),
				'permission_callback' => array( static::class, 'check_gallery_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<page_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( static::class, 'handle_cleanup' ),
				'permission_callback' => array( static::class, 'check_cleanup_permission' ),
			)
		);

		$this->register_moderation_route();
	}

	/**
	 * Register the moderation endpoint for deleting individual photos.
	 *
	 * This route is separate from the bulk cleanup endpoint because it
	 * targets a single attachment and uses moderator-level permissions
	 * rather than administrator-level page-deletion capabilities.
	 *
	 * @return void
	 */
	private function register_moderation_route(): void {
		register_rest_route(
			self::NAMESPACE,
			'/photos/(?P<page_id>\d+)/(?P<attachment_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( static::class, 'handle_moderation' ),
				'permission_callback' => array( static::class, 'check_moderation_permission' ),
				'args'                => array(
					'page_id'       => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'attachment_id' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Handle the auth endpoint request.
	 *
	 * Routes to register, consent, or slideshow_auth based on the action body param.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return array|WP_REST_Response|WP_Error Response data or error.
	 */
	public static function handle_auth( WP_REST_Request $request ): array|WP_REST_Response|WP_Error {
		$page_id = (int) $request->get_param( 'page_id' );

		// Validate that the page exists, is published, and has our block.
		$page_result = self::validate_page( $page_id );
		if ( is_wp_error( $page_result ) ) {
			return $page_result;
		}

		$action = sanitize_key( $request->get_param( 'action' ) ?? '' );

		switch ( $action ) {
			case 'validate_password':
				return self::handle_validate_password( $request, $page_id, $page_result );

			case 'register':
				return self::handle_register( $request, $page_id, $page_result );

			case 'consent':
				return self::handle_consent( $request, $page_id, $page_result );

			case 'slideshow_auth':
				return self::handle_slideshow_auth( $request, $page_id, $page_result );

			default:
				return new WP_Error(
					'egps_invalid_action',
					'The action must be "validate_password", "register", "consent", or "slideshow_auth".',
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Handle the validate_password action.
	 *
	 * Validates the CSRF token, honeypot, and password only — without
	 * requiring guest_name or creating a cookie. This allows the frontend
	 * to verify the password at the password step before transitioning
	 * to the registration (name entry) step.
	 *
	 * On success, returns a fresh CSRF nonce for the subsequent
	 * registration request (since the original nonce is consumed here).
	 *
	 * @param WP_REST_Request $request     The REST request.
	 * @param int             $page_id     The validated page ID.
	 * @param array           $block_attrs Block attributes from validate_page.
	 * @return array|WP_REST_Response|WP_Error Response data or error.
	 */
	private static function handle_validate_password( WP_REST_Request $request, int $page_id, array $block_attrs ): array|WP_REST_Response|WP_Error {
		// 1. Verify CSRF token (one-time use — consumed on success).
		$nonce_error = self::verify_csrf_nonce( $request, $page_id );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		// 2. Issue a fresh CSRF nonce immediately after consuming the old one.
		// This is needed regardless of whether the request succeeds or fails,
		// because the original nonce was already deleted in step 1. Without a
		// fresh nonce, any subsequent retry would fail with "CSRF token invalid".
		$fresh_token = wp_generate_password( 32, false );
		set_transient( 'egps_csrf_' . $fresh_token, $page_id, HOUR_IN_SECONDS );

		// 3. Check honeypot field.
		$honeypot_field = apply_filters( 'egps_honeypot_field_name', 'email' );
		$honeypot_value = $request->get_param( $honeypot_field );
		if ( ! empty( $honeypot_value ) ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 4. Validate password is present.
		$password = $request->get_param( 'password' );
		if ( empty( $password ) ) {
			return new WP_Error(
				'egps_missing_fields',
				'The password field is required.',
				array(
					'status' => 400,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 5. Validate password minimum length.
		$block_password = $block_attrs['password'] ?? '';

		/** This filter is documented in self::handle_register(). */
		$min_length = (int) apply_filters( 'egps_password_min_length', 8 );

		if ( strlen( $block_password ) < $min_length ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 6. Validate password with timing-safe comparison.
		if ( ! hash_equals( $block_password, $password ) ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 7. Return success with the fresh nonce for the registration step.
		return rest_ensure_response(
			array(
				'valid' => true,
				'nonce' => $fresh_token,
			)
		);
	}

	/**
	 * Handle the register action.
	 *
	 * Validates CSRF token, honeypot, password, and required fields.
	 * On success, creates an HMAC-signed cookie and returns a consent nonce.
	 *
	 * @param WP_REST_Request $request    The REST request.
	 * @param int             $page_id    The validated page ID.
	 * @param array           $block_attrs Block attributes from validate_page.
	 * @return array|WP_REST_Response|WP_Error Response data or error.
	 */
	private static function handle_register( WP_REST_Request $request, int $page_id, array $block_attrs ): array|WP_REST_Response|WP_Error {
		// 1. Verify CSRF token (one-time use — consumed on success).
		$nonce_error = self::verify_csrf_nonce( $request, $page_id );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		// 2. Issue a fresh CSRF nonce immediately after consuming the old one.
		// This ensures retries are possible even if validation fails below,
		// because the original nonce was already deleted in step 1.
		$fresh_token = wp_generate_password( 32, false );
		set_transient( 'egps_csrf_' . $fresh_token, $page_id, HOUR_IN_SECONDS );

		// 3. Check honeypot field.
		// @var string $honeypot_field
		$honeypot_field = apply_filters( 'egps_honeypot_field_name', 'email' );
		$honeypot_value = $request->get_param( $honeypot_field );
		if ( ! empty( $honeypot_value ) ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 4. Validate required fields.
		$password   = $request->get_param( 'password' );
		$guest_name = $request->get_param( 'guest_name' );

		if ( empty( $password ) || empty( $guest_name ) ) {
			return new WP_Error(
				'egps_missing_fields',
				'The password and guest_name fields are required.',
				array(
					'status' => 400,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 5. Validate password minimum length.
		$block_password = $block_attrs['password'] ?? '';

		/**
		 * Filters the minimum password length for event access.
		 *
		 * @since 1.0.0
		 *
		 * @param int $min_length Minimum password length. Default 8.
		 */
		$min_length = (int) apply_filters( 'egps_password_min_length', 8 );

		if ( strlen( $block_password ) < $min_length ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 6. Validate password with timing-safe comparison.
		if ( ! hash_equals( $block_password, $password ) ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 7. Build cookie payload.
		$event_version = $block_attrs['eventVersion'] ?? 1;
		$now           = time();

		/**
		 * Filters the cookie expiry duration in seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param int $expiry Expiry duration in seconds. Default 30 days.
		 */
		$expiry_duration = (int) apply_filters( 'egps_cookie_expiry_duration', 30 * DAY_IN_SECONDS );

		$payload = array(
			'page_id'       => $page_id,
			'event_version' => $event_version,
			'guest_name'    => sanitize_text_field( $guest_name ),
			'table_name'    => sanitize_text_field( (string) ( $request->get_param( 'table_name' ) ?? '' ) ),
			'consent'       => false,
			'registered_at' => $now,
			'expires_at'    => $now + $expiry_duration,
		);

		// 8. Sign and set cookie.
		Cookie::set_for_page( $payload );

		// 9. Generate consent nonce.
		$consent_token = wp_generate_password( 32, false );
		set_transient( 'egps_csrf_' . $consent_token, $page_id, HOUR_IN_SECONDS );

		// 10. Return success response.
		return rest_ensure_response(
			array(
				'state'         => 'consent',
				'consent_nonce' => $consent_token,
			)
		);
	}

	/**
	 * Handle the consent action.
	 *
	 * Verifies the HMAC cookie exists with consent=false, checks the CSRF
	 * nonce, validates event_version, then updates the cookie with consent=true.
	 *
	 * @param WP_REST_Request $request    The REST request.
	 * @param int             $page_id    The validated page ID.
	 * @param array           $block_attrs Block attributes from validate_page.
	 * @return array|WP_REST_Response|WP_Error Response data or error.
	 */
	private static function handle_consent( WP_REST_Request $request, int $page_id, array $block_attrs ): array|WP_REST_Response|WP_Error {
		// 1. Verify HMAC cookie exists with consent === false.
		$cookie_payload = Cookie::get_for_page( $page_id );
		if ( null === $cookie_payload || true === ( $cookie_payload['consent'] ?? true ) ) {
			return new WP_Error(
				'egps_invalid_cookie',
				'A valid registration cookie is required.',
				array( 'status' => 403 )
			);
		}

		// 2. Verify CSRF token (after cookie check to avoid burning the nonce).
		$nonce_error = self::verify_csrf_nonce( $request, $page_id );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		// 3. Verify event_version matches current block attribute.
		$current_version = $block_attrs['eventVersion'] ?? 1;
		if ( (int) $cookie_payload['event_version'] !== (int) $current_version ) {
			return new WP_Error(
				'egps_invalid_event_version',
				'The event has been updated. Please re-register.',
				array( 'status' => 403 )
			);
		}

		// 4. Update cookie with consent=true.
		$cookie_payload['consent'] = true;
		Cookie::set_for_page( $cookie_payload );

		// 5. Return success response.
		return rest_ensure_response(
			array(
				'state' => 'gallery',
			)
		);
	}

	/**
	 * Handle the slideshow_auth action.
	 *
	 * Simplified auth flow for the slideshow block: validates the event
	 * password and immediately sets a cookie with consent pre-granted.
	 * No registration step or personal data collection is needed because
	 * the slideshow is display-only.
	 *
	 * The cookie is created with guest_name='Slideshow', consent=true,
	 * and an empty table_name. This allows the slideshow frontend to
	 * access the gallery endpoint without a separate consent step.
	 *
	 * @param WP_REST_Request $request     The REST request.
	 * @param int             $page_id     The validated page ID.
	 * @param array           $block_attrs Block attributes from validate_page.
	 * @return array|WP_REST_Response|WP_Error Response data or error.
	 */
	private static function handle_slideshow_auth( WP_REST_Request $request, int $page_id, array $block_attrs ): array|WP_REST_Response|WP_Error {
		// 1. Verify CSRF token (one-time use — consumed on success).
		$nonce_error = self::verify_csrf_nonce( $request, $page_id );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		// 2. Issue a fresh CSRF nonce immediately after consuming the old one.
		$fresh_token = wp_generate_password( 32, false );
		set_transient( 'egps_csrf_' . $fresh_token, $page_id, HOUR_IN_SECONDS );

		// 3. Check honeypot field.
		$honeypot_field = apply_filters( 'egps_honeypot_field_name', 'email' );
		$honeypot_value = $request->get_param( $honeypot_field );
		if ( ! empty( $honeypot_value ) ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 4. Validate password is present.
		$password = $request->get_param( 'password' );
		if ( empty( $password ) ) {
			return new WP_Error(
				'egps_missing_fields',
				'The password field is required.',
				array(
					'status' => 400,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 5. Validate password minimum length.
		$block_password = $block_attrs['password'] ?? '';

		/** This filter is documented in self::handle_register(). */
		$min_length = (int) apply_filters( 'egps_password_min_length', 8 );

		if ( strlen( $block_password ) < $min_length ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 6. Validate password with timing-safe comparison.
		if ( ! hash_equals( $block_password, $password ) ) {
			return new WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array(
					'status' => 403,
					'nonce'  => $fresh_token,
				)
			);
		}

		// 7. Build cookie payload with pre-granted consent.
		$event_version = $block_attrs['eventVersion'] ?? 1;
		$now           = time();

		/** This filter is documented in self::handle_register(). */
		$expiry_duration = (int) apply_filters( 'egps_cookie_expiry_duration', 30 * DAY_IN_SECONDS );

		$payload = array(
			'page_id'       => $page_id,
			'event_version' => $event_version,
			'guest_name'    => 'Slideshow',
			'table_name'    => '',
			'consent'       => true,
			'registered_at' => $now,
			'expires_at'    => $now + $expiry_duration,
		);

		// 8. Sign and set cookie.
		Cookie::set_for_page( $payload );

		// 9. Return success response with the fresh nonce.
		return rest_ensure_response(
			array(
				'state' => 'slideshow',
				'nonce' => $fresh_token,
			)
		);
	}

	/**
	 * Verify the CSRF nonce from the X-EGPS-Nonce header.
	 *
	 * Checks that the transient exists and matches the page ID,
	 * then deletes it (one-time use).
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param int             $page_id The expected page ID.
	 * @return WP_Error|null Error if invalid, null if valid.
	 */
	private static function verify_csrf_nonce( WP_REST_Request $request, int $page_id ): ?WP_Error {
		$token = $request->get_header( 'X-EGPS-Nonce' );

		if ( empty( $token ) ) {
			return new WP_Error(
				'egps_invalid_nonce',
				'A valid CSRF token is required.',
				array( 'status' => 403 )
			);
		}

		$transient_value = get_transient( 'egps_csrf_' . $token );

		if ( false === $transient_value || (int) $transient_value !== $page_id ) {
			return new WP_Error(
				'egps_invalid_nonce',
				'The CSRF token is invalid or has expired.',
				array( 'status' => 403 )
			);
		}

		// One-time use: delete the transient.
		delete_transient( 'egps_csrf_' . $token );

		return null;
	}

	/**
	 * Validate that a page exists, is published, has the correct type,
	 * and contains the event-album block.
	 *
	 * On success, returns the block's attribute array. On failure, returns a WP_Error.
	 * This method is reused by upload and gallery endpoints (Tasks 5+).
	 *
	 * @param int $page_id The page ID to validate.
	 * @return array|WP_Error Block attributes on success, WP_Error on failure.
	 */
	public static function validate_page( int $page_id ): array|WP_Error {
		if ( get_post_status( $page_id ) !== 'publish' ) {
			return new WP_Error(
				'egps_invalid_page',
				'The requested page does not exist or is not published.',
				array( 'status' => 404 )
			);
		}

		if ( get_post_type( $page_id ) !== 'page' ) {
			return new WP_Error(
				'egps_invalid_page',
				'The requested page does not exist or is not published.',
				array( 'status' => 404 )
			);
		}

		if ( ! has_block( self::BLOCK_NAME, $page_id ) ) {
			return new WP_Error(
				'egps_invalid_page',
				'The requested page does not contain an event album.',
				array( 'status' => 404 )
			);
		}

		$attrs = self::get_block_attributes( $page_id );
		if ( null === $attrs ) {
			return new WP_Error(
				'egps_invalid_page',
				'The event album block could not be found.',
				array( 'status' => 404 )
			);
		}

		return $attrs;
	}

	/**
	 * Extract the event-album block attributes from a page's post content.
	 *
	 * Parses the post content with parse_blocks() and recursively searches
	 * inner blocks to find the first matching event-album block.
	 *
	 * @param int $page_id The page ID to parse.
	 * @return array|null Block attributes, or null if block not found.
	 */
	public static function get_block_attributes( int $page_id ): ?array {
		$content = get_post_field( 'post_content', $page_id );
		$blocks  = parse_blocks( $content );
		return self::find_block_attrs( $blocks );
	}

	/**
	 * Recursively search blocks for the event-album block and return its attributes.
	 *
	 * @param array $blocks Array of parsed block arrays.
	 * @return array|null Block attributes, or null if block not found.
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

	// ─── Photo upload endpoint ───────────────────────────────────────

	/**
	 * Permission callback for the photo upload endpoint.
	 *
	 * Validates the page, verifies the HMAC cookie with consent=true,
	 * checks event_version match, date range, and per-guest upload limit.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error True if permitted, WP_Error on failure.
	 */
	public static function check_photo_upload_permission( WP_REST_Request $request ): true|WP_Error {
		$page_id = (int) $request->get_param( 'page_id' );

		// 1. Validate page.
		$block_attrs = self::validate_page( $page_id );
		if ( is_wp_error( $block_attrs ) ) {
			return $block_attrs;
		}

		// 2-5. Verify cookie, consent, event_version.
		$cookie_error = self::verify_guest_cookie( $page_id, $block_attrs );
		if ( is_wp_error( $cookie_error ) ) {
			return $cookie_error;
		}

		// 6. Check date range if set in block attributes.
		$date_error = self::check_date_range( $block_attrs );
		if ( is_wp_error( $date_error ) ) {
			return $date_error;
		}

		// 7. Check per-guest upload limit.
		$cookie_payload = Cookie::get_for_page( $page_id );
		$guest_id       = null !== $cookie_payload ? Cookie::guest_id( $cookie_payload ) : '';

		/**
		 * Filters the maximum number of uploads per guest.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $max_uploads Maximum uploads per guest. Default 0 (unlimited).
		 * @param string $guest_id    SHA-256 hash identifying the guest.
		 * @param int    $page_id     The event page ID.
		 */
		$max_uploads = (int) apply_filters( 'egps_max_uploads_per_guest', 0, $guest_id, $page_id );
		if ( $max_uploads > 0 && null !== $cookie_payload ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_parent'    => $page_id,
					'post_status'    => 'inherit',
					'posts_per_page' => 1,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_egps_guest_id',
							'value' => $guest_id,
						),
					),
					'fields'         => 'ids',
				)
			);

			if ( $query->found_posts >= $max_uploads ) {
				return new WP_Error(
					'egps_upload_limit_reached',
					'You have reached the maximum number of photo uploads.',
					array( 'status' => 429 )
				);
			}
		}

		return true;
	}

	/**
	 * Handle the photo upload request.
	 *
	 * Validates MIME type, verifies the file is a real image, handles the
	 * upload via wp_handle_upload(), creates a WordPress attachment with
	 * guest metadata, and returns the attachment data.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error Response with attachment data or error.
	 */
	public static function handle_photo_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page_id    = (int) $request->get_param( 'page_id' );
		$files      = $request->get_file_params();
		$photo_file = $files['photo'] ?? null;

		if ( empty( $photo_file ) || empty( $photo_file['tmp_name'] ) ) {
			return new WP_Error(
				'egps_invalid_file_type',
				'No photo file was uploaded.',
				array( 'status' => 415 )
			);
		}

		// 1. Validate MIME type using wp_check_filetype_and_ext on the actual file.
		$file_check = wp_check_filetype_and_ext(
			$photo_file['tmp_name'],
			$photo_file['name']
		);

		$mime_type = $file_check['type'] ?? '';
		if ( empty( $mime_type ) || ! Upload::is_valid_image_type( $mime_type ) ) {
			return new WP_Error(
				'egps_invalid_file_type',
				'The uploaded file type is not allowed.',
				array( 'status' => 415 )
			);
		}

		// 2. Validate that this is a real image using getimagesize().
		// Skip for HEIC/HEIF — getimagesize() doesn't support them on most PHP installs.
		if ( ! in_array( $mime_type, array( 'image/heic', 'image/heif' ), true ) ) {
			$image_info = @getimagesize( $photo_file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $image_info ) {
				return new WP_Error(
					'egps_invalid_image',
					'The uploaded file is not a valid image.',
					array( 'status' => 422 )
				);
			}
		}

		// 3. Handle the upload.
		// File size limits are enforced by WordPress/PHP (upload_max_filesize, post_max_size).
		// The egps_max_file_size filter is available for plugin-level enforcement but not
		// checked here in v1. See spec §1 (File size limits).
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload_result = wp_handle_upload(
			$photo_file,
			array(
				'test_form' => false,
				'action'    => 'egps_photo_upload',
			)
		);

		if ( isset( $upload_result['error'] ) ) {
			return new WP_Error(
				'egps_upload_failed',
				$upload_result['error'],
				array( 'status' => 500 )
			);
		}

		// 4. Create attachment with guest data from cookie.
		$cookie_payload = Cookie::get_for_page( $page_id );
		if ( null === $cookie_payload ) {
			return new WP_Error( 'egps_invalid_cookie', __( 'Invalid or missing authentication.', 'pixfete' ), array( 'status' => 403 ) );
		}
		$guest_data = array(
			'guest_name' => $cookie_payload['guest_name'] ?? '',
			'table_name' => $cookie_payload['table_name'] ?? '',
			'guest_id'   => Cookie::guest_id( $cookie_payload ),
		);

		$attachment_id = Upload::create_attachment(
			$upload_result['file'],
			$photo_file['name'],
			$upload_result['type'],
			$page_id,
			$guest_data
		);

		if ( 0 === $attachment_id ) {
			wp_delete_file( $upload_result['file'] );
			return new WP_Error(
				'egps_upload_failed',
				'Failed to create the attachment.',
				array( 'status' => 500 )
			);
		}

		// 5. Build response.
		$thumbnail_src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		$full_url      = wp_get_attachment_url( $attachment_id );
		$uploaded_at   = get_post_meta( $attachment_id, '_egps_uploaded_at', true );

		$response_data = array(
			'id'          => $attachment_id,
			'thumbnail'   => $thumbnail_src ? $thumbnail_src[0] : $full_url,
			'full'        => $full_url,
			'guest_name'  => get_post_meta( $attachment_id, '_egps_guest_name', true ),
			'uploaded_at' => (int) $uploaded_at,
		);

		$response = new WP_REST_Response( $response_data, 201 );

		return $response;
	}

	// ─── Gallery retrieval endpoint ──────────────────────────────────

	/**
	 * Permission callback for the gallery endpoint.
	 *
	 * Validates the page, verifies the HMAC cookie with consent=true,
	 * and checks event_version match. Does not check date range or file limits.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return true|WP_Error True if permitted, WP_Error on failure.
	 */
	public static function check_gallery_permission( WP_REST_Request $request ): true|WP_Error {
		$page_id = (int) $request->get_param( 'page_id' );

		// 1. Validate page.
		$block_attrs = self::validate_page( $page_id );
		if ( is_wp_error( $block_attrs ) ) {
			return $block_attrs;
		}

		// 2-5. Verify cookie, consent, event_version.
		$cookie_error = self::verify_guest_cookie( $page_id, $block_attrs );
		if ( is_wp_error( $cookie_error ) ) {
			return $cookie_error;
		}

		return true;
	}

	/**
	 * Handle the gallery retrieval request.
	 *
	 * Queries attachments for the page with pagination, excludes moderated
	 * photos, supports filtering by timestamp, and returns response with
	 * total/pages headers.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response Gallery response with photo data and headers.
	 */
	public static function handle_gallery( WP_REST_Request $request ): WP_REST_Response {
		$page_id  = (int) $request->get_param( 'page_id' );
		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?? 30 ), 100 );
		$page     = max( (int) ( $request->get_param( 'page' ) ?? 1 ), 1 );
		$since    = $request->get_param( 'since' );

		if ( $per_page < 1 ) {
			$per_page = 30;
		}

		// Build meta query: always exclude moderated photos.
		$meta_query = array(
			array(
				'relation' => 'OR',
				array(
					'key'     => '_egps_requires_moderation',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_egps_requires_moderation',
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		// If 'since' param is provided, add a meta query for newer photos.
		if ( null !== $since && '' !== $since ) {
			$meta_query[] = array(
				'key'     => '_egps_uploaded_at',
				'value'   => (int) $since,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_parent'    => $page_id,
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_egps_uploaded_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		/**
		 * Filters the gallery WP_Query arguments before execution.
		 *
		 * @since 1.0.0
		 *
		 * @param array $query_args WP_Query arguments.
		 * @param int   $page_id   The event page ID.
		 */
		$query_args = (array) apply_filters( 'egps_gallery_query_args', $query_args, $page_id );

		$query  = new WP_Query( $query_args );
		$photos = array();

		foreach ( $query->posts as $post ) {
			$attachment_id = $post->ID;

			$thumbnail_src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
			$full_url      = wp_get_attachment_url( $attachment_id );

			$photo_data = array(
				'id'          => $attachment_id,
				'thumbnail'   => $thumbnail_src ? $thumbnail_src[0] : $full_url,
				'full'        => $full_url,
				'guest_name'  => get_post_meta( $attachment_id, '_egps_guest_name', true ),
				'table_name'  => get_post_meta( $attachment_id, '_egps_table_name', true ),
				'uploaded_at' => (int) get_post_meta( $attachment_id, '_egps_uploaded_at', true ),
			);

			/**
			 * Filters a single photo response object before it is included in the gallery.
			 *
			 * @since 1.0.0
			 *
			 * @param array $photo_data    Photo response data.
			 * @param int   $attachment_id The attachment post ID.
			 * @param int   $page_id       The event page ID.
			 */
			$photos[] = (array) apply_filters( 'egps_photo_response', $photo_data, $attachment_id, $page_id );
		}

		$response = new WP_REST_Response( $photos );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	// ─── Shared permission helpers ───────────────────────────────────

	/**
	 * Verify the guest cookie for photo/gallery endpoints.
	 *
	 * Checks that a valid HMAC cookie exists for the page, that consent
	 * is true, and that event_version matches the current block attribute.
	 *
	 * @param int   $page_id     The page ID.
	 * @param array $block_attrs Block attributes from validate_page.
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	private static function verify_guest_cookie( int $page_id, array $block_attrs ): true|WP_Error {
		// Verify HMAC cookie exists.
		$cookie_payload = Cookie::get_for_page( $page_id );
		if ( null === $cookie_payload ) {
			return new WP_Error(
				'egps_invalid_cookie',
				'A valid guest cookie is required.',
				array( 'status' => 403 )
			);
		}

		// Consent must be true.
		if ( true !== ( $cookie_payload['consent'] ?? false ) ) {
			return new WP_Error(
				'egps_no_consent',
				'Consent is required to access this resource.',
				array( 'status' => 403 )
			);
		}

		// Event version must match.
		$current_version = $block_attrs['eventVersion'] ?? 1;
		if ( (int) ( $cookie_payload['event_version'] ?? 0 ) !== (int) $current_version ) {
			return new WP_Error(
				'egps_invalid_event_version',
				'The event has been updated. Please re-register.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check whether the current date is within the event's date range.
	 *
	 * If the block attributes include startDate and/or endDate, compares
	 * them against the current date using the site's timezone. Returns a
	 * WP_Error if the event has expired or hasn't started yet.
	 *
	 * @param array $block_attrs Block attributes containing optional dateRangeStart/dateRangeEnd.
	 * @return true|WP_Error True if within range or no range set, WP_Error if expired.
	 */
	private static function check_date_range( array $block_attrs ): true|WP_Error {
		$start_date = $block_attrs['dateRangeStart'] ?? null;
		$end_date   = $block_attrs['dateRangeEnd'] ?? null;

		// No date range set — always valid.
		if ( empty( $start_date ) && empty( $end_date ) ) {
			return true;
		}

		$timezone = wp_timezone();
		$today    = wp_date( 'Y-m-d', null, $timezone );

		if ( ! empty( $start_date ) && $today < $start_date ) {
			return new WP_Error(
				'egps_event_expired',
				'This event is not yet accepting uploads.',
				array( 'status' => 403 )
			);
		}

		if ( ! empty( $end_date ) && $today > $end_date ) {
			return new WP_Error(
				'egps_event_expired',
				'This event has ended and is no longer accepting uploads.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for the event cleanup (DELETE) endpoint.
	 *
	 * Verifies that the currently authenticated WordPress user holds the
	 * delete_post capability for the target event page. This relies on the
	 * standard WP REST nonce (X-WP-Nonce) to establish user identity — no
	 * separate CSRF or guest-cookie mechanism is used here.
	 *
	 * Returning true allows WordPress to proceed to the handle_cleanup()
	 * callback. Returning a WP_Error short-circuits the request and sends
	 * the error response directly to the client.
	 *
	 * @param WP_REST_Request $request The incoming REST request, which must
	 *                                  include a valid X-WP-Nonce header.
	 * @return true|WP_Error True if the user has permission, WP_Error with
	 *                        code egps_forbidden and HTTP 403 if not.
	 */
	public static function check_cleanup_permission( WP_REST_Request $request ): true|WP_Error {
		$page_id = (int) $request->get_param( 'page_id' );

		if ( ! current_user_can( 'delete_post', $page_id ) ) {
			return new WP_Error(
				'egps_forbidden',
				'You do not have permission to delete this event.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle the event cleanup (DELETE) request.
	 *
	 * Delegates all deletion work to Cleanup::delete_event(), which
	 * validates the page, removes all guest photo attachments, deletes any
	 * ZIP archive, and permanently removes the event page itself.
	 *
	 * On success, returns a 200 response containing a summary array with
	 * the counts and flags reported by Cleanup::delete_event(). On failure
	 * (e.g., invalid page or deletion error), the WP_Error returned by
	 * Cleanup::delete_event() is propagated directly so that WordPress can
	 * serialise it as a standard REST error response.
	 *
	 * @param WP_REST_Request $request The incoming REST request. The page_id
	 *                                  route param must match a valid, published
	 *                                  event page containing our block.
	 * @return WP_REST_Response|WP_Error 200 response with deletion summary on
	 *                                    success, or a WP_Error on failure.
	 */
	public static function handle_cleanup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page_id = (int) $request->get_param( 'page_id' );

		$result = Cleanup::delete_event( $page_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Permission callback for the photo moderation (DELETE) endpoint.
	 *
	 * Verifies that the current user is authenticated, holds the
	 * moderation capability, is assigned as a moderator for the
	 * specific page, and that the target attachment actually belongs
	 * to that page. This layered approach prevents both unauthorized
	 * access and cross-page deletion attacks.
	 *
	 * @param WP_REST_Request $request The incoming REST request containing
	 *                                  page_id and attachment_id route params.
	 *
	 * @return true|WP_Error True when all checks pass, WP_Error otherwise.
	 */
	public static function check_moderation_permission( WP_REST_Request $request ): true|WP_Error {
		$page_id       = (int) $request->get_param( 'page_id' );
		$attachment_id = (int) $request->get_param( 'attachment_id' );

		// 1. Must be logged in.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'egps_unauthorized',
				'You must be logged in to moderate photos.',
				array( 'status' => 401 )
			);
		}

		// 2. Must hold the moderation capability or be an admin.
		if ( ! current_user_can( Moderator::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'egps_forbidden',
				'You do not have permission to moderate photos.',
				array( 'status' => 403 )
			);
		}

		// 3. The page must be valid (published, correct type, contains our block).
		$page_result = self::validate_page( $page_id );
		if ( is_wp_error( $page_result ) ) {
			return $page_result;
		}

		// 4. The user must be assigned as a moderator for this specific page.
		$user_id = get_current_user_id();
		if ( ! Moderator::is_moderator_for_page( $user_id, $page_id ) ) {
			return new WP_Error(
				'egps_forbidden',
				'You are not assigned as a moderator for this event.',
				array( 'status' => 403 )
			);
		}

		// 5. The attachment must exist.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return new WP_Error(
				'egps_not_found',
				'The requested photo does not exist.',
				array( 'status' => 404 )
			);
		}

		// 6. The attachment must belong to the specified page.
		if ( wp_get_post_parent_id( $attachment_id ) !== $page_id ) {
			return new WP_Error(
				'egps_forbidden',
				'This photo does not belong to the specified event.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle the photo moderation (DELETE) request.
	 *
	 * Permanently removes a single guest-uploaded attachment. This is
	 * the moderator's primary tool for removing inappropriate or
	 * off-topic photos from an event album. The attachment is force-
	 * deleted (bypassing trash) because guest photos have no revision
	 * history worth preserving and lingering in trash would still
	 * consume server storage.
	 *
	 * @param WP_REST_Request $request The incoming REST request containing
	 *                                  the attachment_id route param.
	 *
	 * @return WP_REST_Response 204 on success, 404 if wp_delete_attachment fails.
	 */
	public static function handle_moderation( WP_REST_Request $request ): WP_REST_Response {
		$attachment_id = (int) $request->get_param( 'attachment_id' );

		$deleted = wp_delete_attachment( $attachment_id, true );

		if ( ! $deleted ) {
			return new WP_REST_Response(
				array( 'message' => 'The photo could not be deleted.' ),
				404
			);
		}

		return new WP_REST_Response( null, 204 );
	}
}
