<?php
/**
 * Permanent deletion of all event data.
 *
 * When a site owner decides to remove an event entirely, they need more than
 * just deleting the page — every trace of the event must go: guest-uploaded
 * attachments (database rows and physical files), the generated ZIP archive
 * (file and option entry), and the event page itself. Doing this in one
 * atomic operation prevents orphaned uploads from accumulating on disk and
 * ensures the option table stays tidy.
 *
 * @package Jeherve\Pixfete
 */

declare( strict_types=1 );

namespace Jeherve\Pixfete;

use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates permanent deletion of all data associated with an event page.
 *
 * This is intentionally a one-way, destructive operation. It is called from
 * the admin DELETE REST endpoint and is designed to be independently testable
 * so the deletion logic can be verified without the HTTP layer.
 */
class Cleanup {

	/**
	 * Permanently delete all data for an event page.
	 *
	 * Deletes in dependency order to avoid leaving orphans if an early step
	 * fails: attachments first (they belong to the page), then the ZIP archive
	 * (its option entry references the page), then any slideshow pages that
	 * reference this event, and finally the event page itself.
	 *
	 * After deletion, fires the `pixfete_after_event_cleanup` action so other
	 * code (audit logging, cache invalidation, etc.) can react.
	 *
	 * @param int $page_id The event page ID to delete.
	 * @return array{deleted_attachments: int, deleted_archive: bool, deleted_slideshow_pages: int, deleted_page: true}|WP_Error
	 *         Summary array on success, WP_Error if the page is invalid.
	 */
	public static function delete_event( int $page_id ): array|WP_Error {
		// Validate before doing anything destructive — if the page isn't a
		// valid published event page, bail immediately rather than deleting
		// unrelated attachments or producing a misleading success result.
		// Reuse REST::validate_page() to ensure the page exists, is published,
		// is a page post type, and contains the event-album block. This couples
		// Cleanup to REST, but avoids duplicating the four-check validation logic.
		$validation = REST::validate_page( $page_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Step 1: Delete all guest-uploaded attachments.
		// force=true bypasses the trash and permanently removes both the
		// database row and the physical image files on disk.
		$attachment_query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $page_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$deleted_attachments = 0;
		foreach ( $attachment_query->posts as $attachment_id ) {
			if ( false !== wp_delete_attachment( (int) $attachment_id, true ) ) {
				++$deleted_attachments;
			}
		}

		// Step 2: Delete the ZIP archive if one exists.
		// The file must be removed from disk before the option entry is cleared,
		// because delete_archive() only removes the metadata — there is no
		// recovery path once that reference is gone.
		$deleted_archive = false;
		$archive         = Archive::get_archive( $page_id );
		if ( null !== $archive ) {
			$file_path = $archive['file_path'] ?? '';
			if ( ! empty( $file_path ) ) {
				wp_delete_file( $file_path );
			}
			Archive::delete_archive( $page_id );
			$deleted_archive = true;

			// Clear any scheduled batch cron jobs for this page so they don't
			// fire after the archive and page have been deleted.
			wp_clear_scheduled_hook( Archive::BATCH_HOOK, array( $page_id ) );
		}

		// Step 3: Delete slideshow pages that reference this event.
		// Slideshow blocks live on separate pages and reference the event via
		// an eventPageId attribute. When the event is removed, these pages
		// become orphans — they would render an empty or broken slideshow.
		// We search for pages containing the slideshow block name and then
		// verify the parsed block attributes to avoid false positives.
		$deleted_slideshow_pages = 0;
		$slideshow_pages         = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'numberposts'    => 100,
				's'              => 'pixfete/event-slideshow',
				'search_columns' => array( 'post_content' ),
				'no_found_rows'  => true,
			)
		);
		foreach ( $slideshow_pages as $slideshow_page ) {
			$content = get_post_field( 'post_content', $slideshow_page->ID );
			$blocks  = parse_blocks( $content );
			if ( self::has_slideshow_for_event( $blocks, $page_id ) ) {
				if ( false !== wp_delete_post( $slideshow_page->ID, true ) ) {
					++$deleted_slideshow_pages;
				}
			}
		}

		// Step 4: Delete the event page itself.
		// force=true skips the trash, matching the destructive intent of this
		// operation. Attachments were already removed above so WordPress will
		// not attempt to re-delete them during post deletion.
		wp_delete_post( $page_id, true );

		$summary = array(
			'deleted_attachments'     => $deleted_attachments,
			'deleted_archive'         => $deleted_archive,
			'deleted_slideshow_pages' => $deleted_slideshow_pages,
			'deleted_page'            => true,
		);

		/**
		 * Fires after all event data has been permanently deleted.
		 *
		 * Allows external code to react to the cleanup — for example to
		 * invalidate cached gallery data, write an audit log entry, or
		 * trigger a notification.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $page_id The deleted event page ID.
		 * @param array $summary Deletion summary with keys:
		 *                       - deleted_attachments    (int)  Number of attachments removed.
		 *                       - deleted_archive        (bool) Whether a ZIP archive was removed.
		 *                       - deleted_slideshow_pages (int) Number of orphaned slideshow pages removed.
		 *                       - deleted_page           (true) Always true at this point.
		 */
		do_action( 'pixfete_after_event_cleanup', $page_id, $summary );

		return $summary;
	}

	/**
	 * Recursively check if any block is a slideshow referencing the given event page.
	 *
	 * When deleting an event, we need to find slideshow pages that point to it.
	 * Slideshow blocks may be nested inside group blocks, columns, or other
	 * container blocks, so a flat search is not sufficient — we must walk the
	 * entire inner-block tree to find matches.
	 *
	 * @param array $blocks  Parsed blocks array from parse_blocks().
	 * @param int   $page_id The event page ID to match against.
	 * @return bool True if a matching slideshow block is found.
	 */
	private static function has_slideshow_for_event( array $blocks, int $page_id ): bool {
		foreach ( $blocks as $block ) {
			if (
				'pixfete/event-slideshow' === ( $block['blockName'] ?? '' )
				&& ( (int) ( $block['attrs']['eventPageId'] ?? 0 ) ) === $page_id
			) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && self::has_slideshow_for_event( $block['innerBlocks'], $page_id ) ) {
				return true;
			}
		}
		return false;
	}
}
