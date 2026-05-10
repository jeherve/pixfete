import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { isEventEnded } from '../utils/is-event-ended';

/**
 * Renders a destructive action section for permanently deleting an event's
 * page, all uploaded photos, and any generated archive files.
 *
 * The section is hidden while the event is still active (i.e. an end date is
 * set and that date is in the future). Once the event has ended — or if no end
 * date was ever configured — the delete button is shown so that organisers can
 * clean up after the event.
 *
 * @param {Object}   props
 * @param {number}   props.pageId         The WordPress page ID for the event.
 * @param {string}   props.dateRangeEnd   The event's end date in Y-m-d format,
 *                                        or an empty string if none is set.
 * @param {Function} props.onEventDeleted Callback fired after a successful
 *                                        deletion, receiving the deleted pageId
 *                                        as its only argument.
 * @return {JSX.Element|null} The cleanup section UI, or null when the event is
 *                            still active.
 */
export function EventCleanup({ pageId, dateRangeEnd, onEventDeleted }) {
	const [isDeleting, setIsDeleting] = useState(false);
	const [error, setError] = useState(null);

	// Hide the section while the event is still active. We only show the
	// delete option once it has ended (or when no end date is configured).
	if (dateRangeEnd && !isEventEnded(dateRangeEnd)) {
		return null;
	}

	/**
	 * Handles the delete button click.
	 *
	 * Prompts the user for confirmation before sending a DELETE request to the
	 * REST API. On success the parent is notified via onEventDeleted so it can
	 * update its own state (e.g. remove the event from a list). On failure the
	 * error message returned by the API is surfaced in a dismissible notice.
	 *
	 * @return {Promise<void>}
	 */
	async function handleDelete() {
		/* global confirm */
		// eslint-disable-next-line no-alert
		const confirmed = confirm(
			__(
				'Are you sure? This will permanently delete the event page, all photos, and archive files. This cannot be undone.',
				'pixfete'
			)
		);
		if (!confirmed) {
			return;
		}

		setIsDeleting(true);
		setError(null);

		try {
			/* global wpApiSettings */
			const response = await fetch(`${wpApiSettings.root}pixfete/v1/events/${pageId}`, {
				method: 'DELETE',
				headers: {
					'X-WP-Nonce': wpApiSettings.nonce,
				},
			});

			if (!response.ok) {
				const body = await response.json();
				throw new Error(body.message);
			}

			onEventDeleted(pageId);
		} catch (err) {
			setError(err.message);
		} finally {
			setIsDeleting(false);
		}
	}

	return (
		<div className="egps-cleanup-section">
			<p>{__('Permanently delete this event page, all uploaded photos, and any archive files.', 'pixfete')}</p>
			{error && (
				<Notice status="error" isDismissible onRemove={() => setError(null)}>
					{error}
				</Notice>
			)}
			<Button variant="secondary" isDestructive onClick={handleDelete} disabled={isDeleting}>
				{isDeleting ? (
					<>
						<Spinner />
						{__('Deleting…', 'pixfete')}
					</>
				) : (
					__('Delete Event Data', 'pixfete')
				)}
			</Button>
		</div>
	);
}
