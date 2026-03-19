<?php
/**
 * Manage cookies set when guests visit an event page.
 *
 * - The cookie is set when the guest first visits the upload page.
 * - The cookie can only be set if the upload page's URL includes:
 *     - a secret query string, `access_key`.
 *     - a valid album ID, `album_id`.
 * - The cookie is set for a month.
 * - The cookie includes the following data:
 *   - album_id
 *   - user_nicename
 *   - user_nickname
 *   - registration_date (when the cookie was set)
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

namespace Jeherve\Event_Guest_Photos_Sharing;

/**
 * Manage cookies used throughout the plugin
 */
class Cookie {

}
