<?php
/**
 * ZIP archive generation for completed event photos.
 *
 * Manages a cron-based workflow that proactively generates ZIP archives
 * of original event photos after events end. Uses batched processing
 * to stay within PHP time limits on any hosting environment.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

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
		wp_clear_scheduled_hook( self::BATCH_HOOK );
	}

	/**
	 * Process a batch of attachments for ZIP archive generation.
	 *
	 * Adds up to `egps_archive_batch_size` (default 50) attachments to the ZIP,
	 * then either reschedules itself for the next batch or marks the archive
	 * as complete. Uses ZipArchive::addFile() which streams from disk without
	 * loading file contents into PHP memory.
	 *
	 * @param int $page_id The event page ID to process.
	 */
	public static function process_batch( int $page_id ): void {
		$archive = self::get_archive( $page_id );
		if ( null === $archive ) {
			return;
		}

		$status = $archive['status'] ?? '';
		if ( ! in_array( $status, array( 'pending', 'generating' ), true ) ) {
			return;
		}

		$upload_dir = wp_get_upload_dir();

		/**
		 * Filters the directory path for ZIP archives.
		 *
		 * @since 1.2.0
		 *
		 * @param string $directory Absolute path to the archive storage directory.
		 */
		$archive_dir = apply_filters(
			'egps_archive_directory',
			$upload_dir['basedir'] . '/egps-archives'
		);

		wp_mkdir_p( $archive_dir );

		// Create index.php to prevent directory listing on first run.
		$index_file = $archive_dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a static directory listing guard, not user content.
		}

		// Initialize file path and URL on first batch.
		if ( 'pending' === $status ) {
			$token     = $archive['token'];
			$file_path = $archive_dir . '/egps-archive-' . $page_id . '-' . $token . '.zip';
			// Note: this URL assumes the default archive directory. If the
			// egps_archive_directory filter changes the storage path to a location
			// outside the uploads directory, this URL will not match. A companion
			// egps_archive_url filter could be added in the future if needed.
			$url = $upload_dir['baseurl'] . '/egps-archives/egps-archive-' . $page_id . '-' . $token . '.zip';

			self::update_archive(
				$page_id,
				array(
					'status'    => 'generating',
					'file_path' => $file_path,
					'url'       => $url,
				)
			);

			$archive['file_path'] = $file_path;
			$archive['url']       = $url;
			$archive['status']    = 'generating';
		}

		/**
		 * Filters the number of attachments processed per batch.
		 *
		 * @since 1.2.0
		 *
		 * @param int $batch_size Number of attachments per batch. Default 50.
		 */
		$batch_size  = (int) apply_filters( 'egps_archive_batch_size', 50 );
		$last_offset = (int) ( $archive['last_offset'] ?? 0 );

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $page_id,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $batch_size,
				'offset'         => $last_offset,
			)
		);

		$zip    = new \ZipArchive();
		$result = $zip->open( $archive['file_path'], \ZipArchive::CREATE );

		if ( true !== $result ) {
			if ( file_exists( $archive['file_path'] ) ) {
				wp_delete_file( $archive['file_path'] );
			}
			self::update_archive( $page_id, array( 'status' => 'failed' ) );
			return;
		}

		// Track names already used in the ZIP to handle duplicates.
		// Collect existing names from prior batches.
		$used_names = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's built-in property name.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$used_names[] = $zip->getNameIndex( $i );
		}

		foreach ( $query->posts as $attachment ) {
			$file_path = wp_get_original_image_path( $attachment->ID );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				$file_path = get_attached_file( $attachment->ID );
			}

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}

			$basename = wp_basename( $file_path );
			$name     = $basename;

			// Deduplicate: append attachment ID if the name is already taken.
			if ( in_array( $name, $used_names, true ) ) {
				$ext  = pathinfo( $basename, PATHINFO_EXTENSION );
				$stem = pathinfo( $basename, PATHINFO_FILENAME );
				$name = $stem . '-' . $attachment->ID . ( $ext ? '.' . $ext : '' );
			}

			$used_names[] = $name;
			$zip->addFile( $file_path, $name );
		}

		$zip->close();

		$new_offset = $last_offset + count( $query->posts );

		if ( $new_offset < $query->found_posts ) {
			// More attachments remain — update offset and reschedule.
			self::update_archive(
				$page_id,
				array( 'last_offset' => $new_offset )
			);
			wp_schedule_single_event( time(), self::BATCH_HOOK, array( $page_id ) );
		} else {
			// All done — finalize the archive entry.
			$final_data = array(
				'status'     => 'complete',
				'file_path'  => $archive['file_path'],
				'url'        => $archive['url'],
				'created_at' => time(),
			);

			// Remove last_offset from the entry by replacing the entire entry.
			$archives = self::get_archives();
			$existing = $archives[ $page_id ] ?? array();
			$merged   = array_merge( $existing, $final_data );
			unset( $merged['last_offset'] );
			$archives[ $page_id ] = $merged;
			update_option( self::OPTION_NAME, $archives );
		}
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
