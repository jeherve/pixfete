/**
 * English i18n fixture used by view-store tests.
 *
 * Mirrors the keys defined in src/blocks/event-album/render.php so tests
 * can assert against the same user-facing strings the PHP renderer emits.
 */
module.exports = {
	passwordRequired: 'Please enter the event password.',
	passwordIncorrect: 'The password is incorrect.',
	initFailed: 'Could not initialize. Please try again.',
	initConnectionFailed: 'Could not initialize. Please check your connection and try again.',
	nameRequired: 'Please enter your name.',
	networkError: 'A network error occurred. Please try again.',
	registrationFailed: 'Registration failed. Please try again.',
	consentFailed: 'Failed to accept consent. Please try again.',
	loadPhotosFailed: 'Failed to load photos.',
	uploadFailed: 'Upload failed. Please try again.',
	uploadConnectionFailed: 'Upload failed. Please check your connection and try again.',
	uploadBulkFailed: '%1$d of %2$d photos failed to upload.',
	confirmDeletePhoto: '%s — delete this photo? This cannot be undone.',
	deletePhotoFailed: 'Failed to delete photo. Please try again.',
	deleteNetworkError: 'Network error. Please try again.',
	newPhotoBannerSingle: '%d new photo — tap to see',
	newPhotoBannerPlural: '%d new photos — tap to see',
};
