<?php
/**
 * ZIP archive generation for completed event photos.
 *
 * Manages a cron-based workflow that proactively generates ZIP archives
 * of original event photos after events end. Uses batched processing
 * to stay within PHP time limits on any hosting environment.
 *
 * @package Jeherve\Event_Guest_Photos_Sharing
 */

declare( strict_types=1 );

namespace Jeherve\Event_Guest_Photos_Sharing;

defined( 'ABSPATH' ) || exit;

/**
 * Handles ZIP archive creation, cron scheduling, and archive metadata storage.
 */
class Archive {

	/**
	 * Option name for storing archive metadata keyed by page ID.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'egps_zip_archives';

	/**
	 * Get all archive entries.
	 *
	 * @return array<int, array<string, mixed>> Archive data keyed by page ID.
	 */
	public static function get_archives(): array {
		return (array) get_option( self::OPTION_NAME, array() );
	}

	/**
	 * Get the archive entry for a single page.
	 *
	 * @param int $page_id The event page ID.
	 * @return array<string, mixed>|null The archive entry, or null if none exists.
	 */
	public static function get_archive( int $page_id ): ?array {
		$archives = self::get_archives();
		return $archives[ $page_id ] ?? null;
	}

	/**
	 * Create or update an archive entry for a page.
	 *
	 * Merges the provided data into the existing entry (if any) so callers
	 * can update individual fields without overwriting the rest.
	 *
	 * @param int                  $page_id The event page ID.
	 * @param array<string, mixed> $data    Data to merge into the entry.
	 */
	public static function update_archive( int $page_id, array $data ): void {
		$archives             = self::get_archives();
		$archives[ $page_id ] = array_merge( $archives[ $page_id ] ?? array(), $data );
		update_option( self::OPTION_NAME, $archives );
	}

	/**
	 * Remove an archive entry for a page.
	 *
	 * Does not delete the ZIP file on disk — callers are responsible for
	 * cleaning up the filesystem before calling this method.
	 *
	 * @param int $page_id The event page ID.
	 */
	public static function delete_archive( int $page_id ): void {
		$archives = self::get_archives();
		unset( $archives[ $page_id ] );
		update_option( self::OPTION_NAME, $archives );
	}
}
