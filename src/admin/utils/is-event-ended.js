/**
 * Check whether an event's end date is in the past.
 *
 * Compares the date string against today's date (local browser time).
 * This is an approximation — the server uses wp_timezone() — but is
 * sufficient for a UI hint since the cron job is the source of truth.
 *
 * @param {string} dateRangeEnd Date string in Y-m-d format.
 * @return {boolean} True if the event has ended.
 */
export function isEventEnded(dateRangeEnd) {
	const today = new Date().toISOString().slice(0, 10);
	return today > dateRangeEnd;
}
