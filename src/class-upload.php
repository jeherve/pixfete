<?php
/**
 * Manage the display of the upload page.
 * The Upload page is used to upload photos to an existing album.
 * You can only load the uploader if the following conditions are met:
 * - The album ID is valid.
 * - The album is published.
 * - A query string is present, and matches the album's access key.
 * - The album's published date matches today's date.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

namespace Jeherve\Event_Guest_Photos_Sharing;

/**
 * Manage the display of the upload page.
 */
class Upload {

}
