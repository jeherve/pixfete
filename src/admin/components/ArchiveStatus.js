import { __ } from '@wordpress/i18n';
import { Spinner, Button } from '@wordpress/components';
import { isEventEnded } from '../utils/is-event-ended';

/**
 * Displays the ZIP archive status for an event page.
 *
 * Renders different messages depending on whether the event has ended,
 * the archive is queued/generating/complete/failed, or no archive exists yet.
 *
 * @param {Object}      props
 * @param {Object|null} props.archive      Archive entry from egps_zip_archives option, or null.
 * @param {string}      props.dateRangeEnd The event's end date (Y-m-d string), or empty.
 * @return {JSX.Element|null} The rendered archive status UI.
 */
export function ArchiveStatus({ archive, dateRangeEnd }) {
	// If no end date is set, or the event hasn't ended yet, show a waiting message.
	if (!dateRangeEnd || !isEventEnded(dateRangeEnd)) {
		return <p>{__('The photo archive will be available once the event ends.', 'event-guest-photos-sharing')}</p>;
	}

	// Event has ended but no archive entry exists yet (cron hasn't run).
	if (!archive) {
		return <p>{__('Photo archive is queued for generation.', 'event-guest-photos-sharing')}</p>;
	}

	const { status, url } = archive;

	switch (status) {
		case 'pending':
			return <p>{__('Photo archive is queued for generation.', 'event-guest-photos-sharing')}</p>;

		case 'generating':
			return (
				<p>
					{__('Photo archive is being generated…', 'event-guest-photos-sharing')} <Spinner />
				</p>
			);

		case 'complete':
			return (
				<div>
					<p>{__('Photo archive ready.', 'event-guest-photos-sharing')}</p>
					<Button variant="secondary" href={url} download>
						{__('Download ZIP', 'event-guest-photos-sharing')}
					</Button>
				</div>
			);

		case 'failed':
			return <p>{__('Archive generation failed.', 'event-guest-photos-sharing')}</p>;

		default:
			return null;
	}
}
