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
	 * Cron hook name for the daily archive check.
	 *
	 * @var string
	 */
	public const DAILY_HOOK = 'egps_daily_archive_check';

	/**
	 * Cron hook name for processing a single batch of attachments.
	 *
	 * @var string
	 */
	public const BATCH_HOOK = 'egps_archive_build_batch';

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

	/**
	 * Schedule the daily archive check cron event.
	 *
	 * Called on plugin activation. Registers a daily recurring event
	 * that scans for completed events needing ZIP generation.
	 */
	public static function schedule_cron(): void {
		if ( wp_next_scheduled( self::DAILY_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'daily', self::DAILY_HOOK );
	}

	/**
	 * Remove the daily archive check cron event.
	 *
	 * Called on plugin deactivation to clean up scheduled events.
	 */
	public static function unschedule_cron(): void {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * Daily cron callback: find completed events and kick off ZIP generation.
	 *
	 * Scans all published pages with the event-album block, checks whether
	 * each event has ended (dateRangeEnd is in the past), and schedules
	 * batch processing for any that don't already have an archive entry.
	 */
	public static function check_events(): void {
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		foreach ( $pages as $page ) {
			$attrs = REST::get_block_attributes( $page->ID );
			if ( null === $attrs ) {
				continue;
			}

			$end_date = $attrs['dateRangeEnd'] ?? '';
			if ( empty( $end_date ) ) {
				continue;
			}

			// Compare against today in the site's timezone.
			$timezone = wp_timezone();
			$today    = wp_date( 'Y-m-d', null, $timezone );
			if ( $today <= $end_date ) {
				continue;
			}

			// Skip if an archive entry already exists (any status).
			if ( null !== self::get_archive( $page->ID ) ) {
				continue;
			}

			// Skip if the page has no attachments.
			$attachment_query = new \WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_parent'    => $page->ID,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( 0 === $attachment_query->found_posts ) {
				continue;
			}

			// Create a pending archive entry and schedule the first batch.
			$token = wp_generate_password( 12, false );
			self::update_archive(
				$page->ID,
				array(
					'status'      => 'pending',
					'token'       => $token,
					'last_offset' => 0,
				)
			);

			wp_schedule_single_event( time(), self::BATCH_HOOK, array( $page->ID ) );
		}
	}
}
