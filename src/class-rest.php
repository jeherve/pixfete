<?php
/**
 * Register REST API endpoints for guest authentication and consent.
 *
 * Handles the two-step auth flow: registration (password validation,
 * cookie creation) and consent (cookie update). Uses CSRF tokens stored
 * as transients and HMAC-signed cookies for stateless guest auth.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for guest authentication endpoints.
 */
class REST extends \WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'event-guest-photos-sharing/v1';

	/**
	 * Block name for the event album.
	 *
	 * @var string
	 */
	private const BLOCK_NAME = 'event-guest-photos-sharing/event-album';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/auth/(?P<page_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( static::class, 'handle_auth' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle the auth endpoint request.
	 *
	 * Routes to register or consent based on the action body param.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return array|\WP_Error Response data or error.
	 */
	public static function handle_auth( \WP_REST_Request $request ): array|\WP_Error {
		$page_id = (int) $request->get_param( 'page_id' );

		// Validate that the page exists, is published, and has our block.
		$page_result = self::validate_page( $page_id );
		if ( is_wp_error( $page_result ) ) {
			return $page_result;
		}

		$action = sanitize_key( $request->get_param( 'action' ) ?? '' );

		switch ( $action ) {
			case 'register':
				return self::handle_register( $request, $page_id, $page_result );

			case 'consent':
				return self::handle_consent( $request, $page_id, $page_result );

			default:
				return new \WP_Error(
					'egps_invalid_action',
					'The action must be "register" or "consent".',
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Handle the register action.
	 *
	 * Validates CSRF token, honeypot, password, and required fields.
	 * On success, creates an HMAC-signed cookie and returns a consent nonce.
	 *
	 * @param \WP_REST_Request $request    The REST request.
	 * @param int              $page_id    The validated page ID.
	 * @param array            $block_attrs Block attributes from validate_page.
	 * @return array|\WP_Error Response data or error.
	 */
	private static function handle_register( \WP_REST_Request $request, int $page_id, array $block_attrs ): array|\WP_Error {
		// 1. Verify CSRF token.
		$nonce_error = self::verify_csrf_nonce( $request, $page_id );
		if ( null !== $nonce_error ) {
			return $nonce_error;
		}

		// 2. Check honeypot field.
		/** @var string $honeypot_field */
		$honeypot_field = apply_filters( 'egps_honeypot_field_name', 'email' );
		$honeypot_value = $request->get_param( $honeypot_field );
		if ( ! empty( $honeypot_value ) ) {
			return new \WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array( 'status' => 403 )
			);
		}

		// 3. Validate required fields.
		$password   = $request->get_param( 'password' );
		$guest_name = $request->get_param( 'guest_name' );

		if ( empty( $password ) || empty( $guest_name ) ) {
			return new \WP_Error(
				'egps_missing_fields',
				'The password and guest_name fields are required.',
				array( 'status' => 400 )
			);
		}

		// 4. Validate password minimum length.
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
			return new \WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array( 'status' => 403 )
			);
		}

		// 5. Validate password with timing-safe comparison.
		if ( ! hash_equals( $block_password, $password ) ) {
			return new \WP_Error(
				'egps_invalid_password',
				'The password is incorrect.',
				array( 'status' => 403 )
			);
		}

		// 6. Build cookie payload.
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

		// 7. Sign and set cookie.
		Cookie::set_for_page( $payload );

		// 8. Generate consent nonce.
		$consent_token = wp_generate_password( 32, false );
		set_transient( 'egps_csrf_' . $consent_token, $page_id, HOUR_IN_SECONDS );

		// 9. Return success response.
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
	 * @param \WP_REST_Request $request    The REST request.
	 * @param int              $page_id    The validated page ID.
	 * @param array            $block_attrs Block attributes from validate_page.
	 * @return array|\WP_Error Response data or error.
	 */
	private static function handle_consent( \WP_REST_Request $request, int $page_id, array $block_attrs ): array|\WP_Error {
		// 1. Verify HMAC cookie exists with consent === false.
		$cookie_payload = Cookie::get_for_page( $page_id );
		if ( null === $cookie_payload || true === ( $cookie_payload['consent'] ?? true ) ) {
			return new \WP_Error(
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
			return new \WP_Error(
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
	 * Verify the CSRF nonce from the X-EGPS-Nonce header.
	 *
	 * Checks that the transient exists and matches the page ID,
	 * then deletes it (one-time use).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @param int              $page_id The expected page ID.
	 * @return \WP_Error|null Error if invalid, null if valid.
	 */
	private static function verify_csrf_nonce( \WP_REST_Request $request, int $page_id ): ?\WP_Error {
		$token = $request->get_header( 'X-EGPS-Nonce' );

		if ( empty( $token ) ) {
			return new \WP_Error(
				'egps_invalid_nonce',
				'A valid CSRF token is required.',
				array( 'status' => 403 )
			);
		}

		$transient_value = get_transient( 'egps_csrf_' . $token );

		if ( false === $transient_value || (int) $transient_value !== $page_id ) {
			return new \WP_Error(
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
	 * @return array|\WP_Error Block attributes on success, WP_Error on failure.
	 */
	public static function validate_page( int $page_id ): array|\WP_Error {
		if ( get_post_status( $page_id ) !== 'publish' ) {
			return new \WP_Error(
				'egps_invalid_page',
				'The requested page does not exist or is not published.',
				array( 'status' => 404 )
			);
		}

		if ( get_post_type( $page_id ) !== 'page' ) {
			return new \WP_Error(
				'egps_invalid_page',
				'The requested page does not exist or is not published.',
				array( 'status' => 404 )
			);
		}

		if ( ! has_block( self::BLOCK_NAME, $page_id ) ) {
			return new \WP_Error(
				'egps_invalid_page',
				'The requested page does not contain an event album.',
				array( 'status' => 404 )
			);
		}

		$attrs = self::get_block_attributes( $page_id );
		if ( null === $attrs ) {
			return new \WP_Error(
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
}
