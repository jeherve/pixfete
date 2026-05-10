/**
 * Frontend Interactivity API store for the Event Photo Album block.
 *
 * Manages the full guest experience: password entry, registration,
 * consent, photo gallery, uploads, polling, and lightbox.
 *
 * @package
 */

import './view.scss';

import { store, getContext } from '@wordpress/interactivity';

/**
 * Number of photos to load per page.
 *
 * @type {number}
 */
const PER_PAGE = 30;

/**
 * Polling interval in milliseconds for checking new photos.
 *
 * @type {number}
 */
const POLL_INTERVAL = 15000;

/**
 * Touch swipe trackers for the lightbox. Module-scoped because they are
 * transient gesture state — not reactive UI state — and should not trigger
 * Interactivity API re-renders.
 */
let lightboxTouchStartX = 0;
let lightboxTouchStartY = 0;

/**
 * Minimum horizontal distance (px) required to register a swipe. Below this,
 * the gesture is treated as a tap or a noisy non-swipe.
 */
const SWIPE_THRESHOLD = 50;

/**
 * Read and decode the Pixfête cookie for a given page ID.
 *
 * The cookie format is `{base64url-encoded JSON}.{HMAC}`. We only need
 * the payload portion (HMAC verification happens server-side).
 *
 * @param {number} pageId The WordPress page ID.
 * @return {Object|null} Decoded cookie payload, or null if not found/invalid.
 */
function readCookie(pageId) {
	const name = `pixfete_${pageId}=`;
	const cookies = document.cookie.split('; ');

	for (const cookie of cookies) {
		if (cookie.startsWith(name)) {
			const value = cookie.substring(name.length);
			const dotIndex = value.indexOf('.');
			if (dotIndex === -1) {
				return null;
			}

			const base64url = value.substring(0, dotIndex);

			try {
				// Convert base64url to standard base64.
				const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
				const json = atob(base64);
				return JSON.parse(json);
			} catch {
				return null;
			}
		}
	}
	return null;
}

/**
 * Remove the `key` and `table` query parameters from the URL
 * without triggering a page reload.
 */
function cleanUrlParams() {
	const url = new URL(window.location.href);
	url.searchParams.delete('key');
	url.searchParams.delete('table');
	window.history.replaceState({}, '', url.toString());
}

const { state } = store('pixfete', {
	state: {
		currentView: 'loading',
		isSubmitting: false,
		errorMessage: '',
		passwordInput: '',
		guestName: '',
		tableName: '',
		photos: [],
		hasMore: false,
		currentPage: 1,
		newPhotoCount: 0,
		pendingPhotos: [],
		consentNonce: '',
		lightboxIndex: -1,
		fabOpen: false,
		latestUploadedAt: 0,
		pollingId: 0,
		uploadTotal: 0,
		uploadCurrent: 0,
		uploadErrors: [],

		/** Whether the current user is an assigned moderator for this event. */
		isModerator: false,

		/** WordPress REST API nonce for authenticated requests (moderators only). */
		restNonce: '',

		/** The attachment ID currently being deleted, or null if idle. */
		deletingPhotoId: null,

		/**
		 * Whether the current view is the loading view.
		 *
		 * @return {boolean} True if showing loading state.
		 */
		get isLoadingView() {
			return state.currentView === 'loading';
		},

		/**
		 * Whether the event has started based on the dateStart attribute.
		 *
		 * Compares today's date (YYYY-MM-DD) against the block's dateStart.
		 * If no dateStart is set, the event is considered started.
		 *
		 * @return {boolean} True if the event has started or no start date is set.
		 */
		get isEventStarted() {
			const ctx = getContext();
			if (!ctx.dateStart) {
				return true;
			}
			const today = new Date().toISOString().substring(0, 10);
			return today >= ctx.dateStart;
		},

		/**
		 * Whether the current view is the "not started" view.
		 *
		 * @return {boolean} True if showing the "event not started" message.
		 */
		get isNotStartedView() {
			return state.currentView === 'not-started';
		},

		/**
		 * Whether the current view is the password entry view.
		 *
		 * @return {boolean} True if showing password form.
		 */
		get isPasswordView() {
			return state.currentView === 'password';
		},

		/**
		 * Whether the current view is the registration view.
		 *
		 * @return {boolean} True if showing registration form.
		 */
		get isRegistrationView() {
			return state.currentView === 'registration';
		},

		/**
		 * Whether the current view is the consent view.
		 *
		 * @return {boolean} True if showing consent form.
		 */
		get isConsentView() {
			return state.currentView === 'consent';
		},

		/**
		 * Whether the current view is the gallery view.
		 *
		 * @return {boolean} True if showing photo gallery.
		 */
		get isGalleryView() {
			return state.currentView === 'gallery';
		},

		/**
		 * Whether the FAB should be visible.
		 *
		 * Hidden when uploads are disabled (outside date range),
		 * when the lightbox is open, or when not in gallery view.
		 *
		 * @return {boolean} True if FAB should be shown.
		 */
		get showFab() {
			return state.isGalleryView && state.isUploadEnabled && !state.lightboxOpen;
		},

		/**
		 * Whether the lightbox is currently open.
		 *
		 * Derived from lightboxIndex so the open state is always consistent
		 * with whether a valid photo index is selected.
		 *
		 * @return {boolean} True if a photo is selected for lightbox display.
		 */
		get lightboxOpen() {
			return state.lightboxIndex >= 0;
		},

		/**
		 * The photo currently displayed in the lightbox.
		 *
		 * Reads from state.photos[lightboxIndex] so the lightbox stays in
		 * sync with the underlying photos array if it mutates (e.g., a
		 * moderator deletes a photo or polling prepends new ones — see
		 * deletePhoto and showNewPhotos for the index-correction logic).
		 *
		 * @return {Object} The active photo object, or an empty fallback when closed.
		 */
		get lightboxPhoto() {
			return state.photos[state.lightboxIndex] ?? { full: '', guest_name: '' };
		},

		/**
		 * Whether the lightbox can navigate to a previous photo.
		 *
		 * @return {boolean} True if the active photo is not the first one.
		 */
		get canGoPrev() {
			return state.lightboxIndex > 0;
		},

		/**
		 * Whether the lightbox can navigate to a next photo.
		 *
		 * @return {boolean} True if the active photo is not the last one.
		 */
		get canGoNext() {
			return state.lightboxIndex >= 0 && state.lightboxIndex < state.photos.length - 1;
		},

		/**
		 * Whether photo uploads are currently enabled based on the event date range.
		 *
		 * Delegates the start-date check to isEventStarted to avoid duplicating
		 * the dateStart comparison logic. Also checks dateEnd to disable uploads
		 * once the event has ended. If no dates are set, uploads are always enabled.
		 *
		 * @return {boolean} True if uploads are allowed.
		 */
		get isUploadEnabled() {
			if (!state.isEventStarted) {
				return false;
			}
			const ctx = getContext();
			const today = new Date().toISOString().substring(0, 10);
			if (ctx.dateEnd && today > ctx.dateEnd) {
				return false;
			}
			return true;
		},

		/**
		 * Whether the table name field should be shown during registration.
		 *
		 * Only shown when enableTableNames is true in block settings AND
		 * there is no `?table=` query parameter pre-filling the value.
		 *
		 * @return {boolean} True if table name input should be visible.
		 */
		get showTableName() {
			const ctx = getContext();
			return ctx.enableTableNames && !state.tableName;
		},

		/**
		 * Build the text for the "new photos available" banner.
		 *
		 * @return {string} Banner text, e.g. "3 new photos — tap to see".
		 */
		get newPhotoBannerText() {
			const count = state.newPhotoCount;
			if (count === 1) {
				return '1 new photo \u2014 tap to see';
			}
			return `${count} new photos \u2014 tap to see`;
		},

		/**
		 * Whether an upload batch is currently in progress.
		 *
		 * @return {boolean} True if files are being uploaded.
		 */
		get isUploading() {
			return state.uploadTotal > 0;
		},

		/**
		 * Build the text for the upload progress banner.
		 *
		 * Shows which file in the batch is currently uploading,
		 * e.g. "📷 2 / 5…".
		 *
		 * @return {string} Banner text with current/total count.
		 */
		get uploadBannerText() {
			if (!state.uploadTotal) {
				return '';
			}
			return `\u{1f4f7} ${state.uploadCurrent} / ${state.uploadTotal}\u2026`;
		},
	},

	actions: {
		/**
		 * Initialize the app state on mount.
		 *
		 * Reads the cookie, checks URL parameters, and determines
		 * which view to show first.
		 */
		init() {
			// Read URL parameters before cleaning.
			const url = new URL(window.location.href);
			const keyParam = url.searchParams.get('key');
			const tableParam = url.searchParams.get('table');

			// Pre-fill table name from URL if present.
			if (tableParam) {
				state.tableName = tableParam;
			}

			// Pre-fill password from URL if present.
			if (keyParam) {
				state.passwordInput = keyParam;
			}

			// Clean URL parameters.
			cleanUrlParams();

			// Short-circuit for future events — show a friendly message
			// instead of the auth flow when the event hasn't started yet.
			if (!state.isEventStarted) {
				state.currentView = 'not-started';
				return;
			}

			// Read the cookie to determine initial state.
			// ctx is retrieved here (after the early return) to satisfy the
			// no-unused-vars-before-return lint rule.
			const ctx = getContext();

			// Sync moderator status from server-rendered context into
			// global state so data-wp-bind directives can read it.
			state.isModerator = ctx.isModerator ?? false;
			state.restNonce = ctx.restNonce ?? '';
			const cookie = readCookie(ctx.pageId);

			if (cookie && cookie.consent === true) {
				// Valid cookie with consent — go to gallery.
				state.currentView = 'gallery';
				// Start loading photos and polling.
				const { actions } = store('pixfete');
				actions.loadPhotos();
				actions.startPolling();
			} else if (cookie && cookie.consent === false) {
				// Valid cookie without consent — show consent screen.
				// Use the page-level CSRF token as the consent nonce, since
				// the normal registration flow (which sets consentNonce) was skipped.
				state.consentNonce = ctx.nonce;
				state.currentView = 'consent';
			} else if (keyParam) {
				// No cookie but key param — go to registration.
				state.currentView = 'registration';
			} else {
				// No cookie, no param — show password form.
				state.currentView = 'password';
			}
		},

		/**
		 * Handle input changes for the password field.
		 *
		 * @param {Event} event The input event.
		 */
		updatePasswordInput(event) {
			state.passwordInput = event.target.value;
		},

		/**
		 * Handle input changes for the guest name field.
		 *
		 * @param {Event} event The input event.
		 */
		updateGuestName(event) {
			state.guestName = event.target.value;
		},

		/**
		 * Handle input changes for the table name field.
		 *
		 * @param {Event} event The input event.
		 */
		updateTableName(event) {
			state.tableName = event.target.value;
		},

		/**
		 * Handle password form submission.
		 *
		 * Validates the password against the server before transitioning
		 * to the registration view. On success, stores the fresh CSRF
		 * nonce returned by the server for the subsequent registration request.
		 *
		 * @param {Event} event The submit event.
		 */
		*submitPassword(event) {
			event.preventDefault();
			state.errorMessage = '';

			if (!state.passwordInput.trim()) {
				state.errorMessage = 'Please enter the event password.';
				return;
			}

			state.isSubmitting = true;
			const ctx = getContext();

			try {
				const body = {
					action: 'validate_password',
					password: state.passwordInput,
				};

				// Include honeypot field (should be empty for real users).
				body[ctx.honeypotField] = '';

				const response = yield fetch(`${ctx.restBase}/auth/${ctx.pageId}`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Pixfete-Nonce': ctx.nonce,
					},
					credentials: 'same-origin',
					body: JSON.stringify(body),
				});

				if (!response.ok) {
					const errorData = yield response.json();
					state.errorMessage = errorData.message || 'The password is incorrect.';

					// Update the nonce from the error response so retries work.
					// The original nonce was consumed during CSRF verification.
					if (errorData.data?.nonce) {
						ctx.nonce = errorData.data.nonce;
					}
					return;
				}

				const data = yield response.json();

				// Store the fresh nonce for the registration step.
				ctx.nonce = data.nonce;
				state.currentView = 'registration';
			} catch {
				state.errorMessage = 'A network error occurred. Please try again.';
			} finally {
				state.isSubmitting = false;
			}
		},

		/**
		 * Handle registration form submission.
		 *
		 * POSTs to the auth endpoint with the password, guest name,
		 * optional table name, and honeypot field. On success,
		 * stores the consent nonce and transitions to the consent view.
		 *
		 * @param {Event} event The submit event.
		 */
		*submitRegistration(event) {
			event.preventDefault();
			state.errorMessage = '';

			if (!state.guestName.trim()) {
				state.errorMessage = 'Please enter your name.';
				return;
			}

			state.isSubmitting = true;
			const ctx = getContext();

			try {
				const body = {
					action: 'register',
					password: state.passwordInput,
					guest_name: state.guestName.trim(),
					table_name: state.tableName.trim(),
				};

				// Include honeypot field (should be empty for real users).
				body[ctx.honeypotField] = '';

				const response = yield fetch(`${ctx.restBase}/auth/${ctx.pageId}`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Pixfete-Nonce': ctx.nonce,
					},
					credentials: 'same-origin',
					body: JSON.stringify(body),
				});

				if (!response.ok) {
					const errorData = yield response.json();
					state.errorMessage = errorData.message || 'Registration failed. Please try again.';

					// Update the nonce from the error response so retries work.
					if (errorData.data?.nonce) {
						ctx.nonce = errorData.data.nonce;
					}
					return;
				}

				const data = yield response.json();
				state.consentNonce = data.consent_nonce || '';
				state.currentView = 'consent';
			} catch {
				state.errorMessage = 'A network error occurred. Please try again.';
			} finally {
				state.isSubmitting = false;
			}
		},

		/**
		 * Handle the consent acceptance.
		 *
		 * POSTs to the auth endpoint with the consent action and nonce.
		 * On success, transitions to the gallery view and starts
		 * loading photos.
		 */
		*acceptConsent() {
			state.errorMessage = '';
			state.isSubmitting = true;
			const ctx = getContext();

			try {
				const response = yield fetch(`${ctx.restBase}/auth/${ctx.pageId}`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Pixfete-Nonce': state.consentNonce,
					},
					credentials: 'same-origin',
					body: JSON.stringify({ action: 'consent' }),
				});

				if (!response.ok) {
					const errorData = yield response.json();
					state.errorMessage = errorData.message || 'Failed to accept consent. Please try again.';
					return;
				}

				state.currentView = 'gallery';

				const { actions } = store('pixfete');
				actions.loadPhotos();
				actions.startPolling();
			} catch {
				state.errorMessage = 'A network error occurred. Please try again.';
			} finally {
				state.isSubmitting = false;
			}
		},

		/**
		 * Load photos from the gallery endpoint.
		 *
		 * Fetches the current page of photos and appends them to the
		 * existing gallery. Parses the X-WP-TotalPages header to
		 * determine if more pages are available.
		 */
		*loadPhotos() {
			const ctx = getContext();
			state.isSubmitting = true;

			try {
				const url = new URL(`${ctx.restBase}/photos/${ctx.pageId}`);
				url.searchParams.set('per_page', String(PER_PAGE));
				url.searchParams.set('page', String(state.currentPage));

				const response = yield fetch(url.toString(), {
					credentials: 'same-origin',
				});

				if (!response.ok) {
					state.errorMessage = 'Failed to load photos.';
					return;
				}

				const photos = yield response.json();
				const totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);

				// Append photos to existing list.
				state.photos = [...state.photos, ...photos];
				state.hasMore = state.currentPage < totalPages;

				// Track latest upload timestamp for polling.
				if (photos.length > 0) {
					const maxTimestamp = Math.max(...photos.map((p) => p.uploaded_at || 0));
					if (maxTimestamp > state.latestUploadedAt) {
						state.latestUploadedAt = maxTimestamp;
					}
				}
			} catch {
				state.errorMessage = 'Failed to load photos.';
			} finally {
				state.isSubmitting = false;
			}
		},

		/**
		 * Load the next page of photos.
		 *
		 * Increments the current page counter and fetches more photos.
		 */
		*loadMore() {
			state.currentPage += 1;
			const { actions } = store('pixfete');
			yield actions.loadPhotos();
		},

		/**
		 * Start polling for new photos at a regular interval.
		 *
		 * Uses the `since` parameter to only fetch photos uploaded
		 * after the latest known timestamp. New photos are held in a
		 * pending queue and shown when the user taps the banner.
		 */
		startPolling() {
			// Avoid duplicate intervals.
			if (state.pollingId) {
				return;
			}

			const ctx = getContext();
			const pageId = ctx.pageId;
			const restBase = ctx.restBase;

			state.pollingId = setInterval(async () => {
				if (!state.latestUploadedAt) {
					return;
				}

				try {
					const url = new URL(`${restBase}/photos/${pageId}`);
					url.searchParams.set('since', String(state.latestUploadedAt));
					url.searchParams.set('per_page', '100');

					const response = await fetch(url.toString(), {
						credentials: 'same-origin',
					});

					if (!response.ok) {
						return;
					}

					const newPhotos = await response.json();
					if (newPhotos.length > 0) {
						// Deduplicate against existing pending and displayed photos.
						const knownIds = new Set([
							...state.pendingPhotos.map((p) => p.id),
							...state.photos.map((p) => p.id),
						]);
						const unique = newPhotos.filter((p) => !knownIds.has(p.id));

						if (unique.length === 0) {
							return;
						}

						state.pendingPhotos = [...unique, ...state.pendingPhotos];
						state.newPhotoCount = state.pendingPhotos.length;

						// Update the latest timestamp.
						const maxTimestamp = Math.max(...newPhotos.map((p) => p.uploaded_at || 0));
						if (maxTimestamp > state.latestUploadedAt) {
							state.latestUploadedAt = maxTimestamp;
						}
					}
				} catch {
					// Silently ignore polling errors.
				}
			}, POLL_INTERVAL);
		},

		/**
		 * Prepend pending photos to the gallery and clear the new-photo banner.
		 *
		 * When the lightbox is open, shifts lightboxIndex by the number of
		 * photos prepended so the user keeps viewing the same image despite
		 * the array growing above it.
		 */
		showNewPhotos() {
			// Deduplicate pending photos against the current gallery.
			const existingIds = new Set(state.photos.map((p) => p.id));
			const unique = state.pendingPhotos.filter((p) => !existingIds.has(p.id));

			// Keep the active lightbox photo visually stable when new photos
			// are prepended above it. Without this shift the index would
			// silently point at a different photo than the user is viewing.
			if (state.lightboxIndex >= 0 && unique.length > 0) {
				state.lightboxIndex += unique.length;
			}

			state.photos = [...unique, ...state.photos];
			state.pendingPhotos = [];
			state.newPhotoCount = 0;
		},

		/**
		 * Handle file selection for photo uploads.
		 *
		 * Uploads each selected file individually via the REST endpoint.
		 * Tracks progress via uploadTotal/uploadCurrent state for the
		 * progress banner. Errors are collected and shown as a summary
		 * after the entire batch completes.
		 *
		 * @param {Event} event The change event from the file input.
		 */
		*handleFileSelect(event) {
			const files = event.target.files;
			if (!files || files.length === 0) {
				return;
			}

			// Guard against concurrent batches — if an upload is already
			// in progress, ignore this file selection entirely.
			if (state.isUploading) {
				return;
			}

			const totalFiles = files.length;
			state.uploadTotal = totalFiles;
			state.uploadCurrent = 1;
			state.uploadErrors = [];
			state.errorMessage = '';
			const ctx = getContext();

			let index = 0;
			for (const file of files) {
				index++;
				state.uploadCurrent = index;

				try {
					const formData = new FormData();
					formData.append('photo', file);

					const response = yield fetch(`${ctx.restBase}/photos/${ctx.pageId}`, {
						method: 'POST',
						credentials: 'same-origin',
						body: formData,
					});

					if (!response.ok) {
						const errorData = yield response.json();
						state.uploadErrors = [
							...state.uploadErrors,
							errorData.message || 'Upload failed. Please try again.',
						];
						continue;
					}

					const photo = yield response.json();

					// Prepend the new photo to the gallery.
					state.photos = [photo, ...state.photos];

					// Update latest timestamp.
					if (photo.uploaded_at && photo.uploaded_at > state.latestUploadedAt) {
						state.latestUploadedAt = photo.uploaded_at;
					}
				} catch {
					state.uploadErrors = [
						...state.uploadErrors,
						'Upload failed. Please check your connection and try again.',
					];
				}
			}

			// Reset upload progress state.
			state.uploadTotal = 0;
			state.uploadCurrent = 0;

			// Show error summary if any uploads failed.
			if (state.uploadErrors.length === 1) {
				state.errorMessage = state.uploadErrors[0];
			} else if (state.uploadErrors.length > 1) {
				state.errorMessage = `${state.uploadErrors.length} of ${totalFiles} photos failed to upload.`;
			}

			// Reset the file input so the same file can be selected again.
			event.target.value = '';
		},

		/**
		 * Delete a photo via the moderation REST endpoint.
		 *
		 * Triggered by the delete badge on each photo in the grid.
		 * Shows a confirmation dialog, sends a DELETE request, and
		 * removes the photo from the local state on success.
		 * Stops event propagation to prevent the lightbox from opening.
		 *
		 * @param {Event} event The click event from the delete badge.
		 */
		*deletePhoto(event) {
			event.stopPropagation();

			const ctx = getContext();
			if (!ctx.item) {
				return;
			}

			const photoId = ctx.item.id;
			const guestName = ctx.item.guest_name;

			// Native confirmation dialog.
			// eslint-disable-next-line no-alert -- Intentional use of confirm for destructive action.
			const confirmed = window.confirm(`${guestName} — delete this photo? This cannot be undone.`);

			if (!confirmed) {
				return;
			}

			state.deletingPhotoId = photoId;

			try {
				const response = yield window.fetch(`${ctx.restBase}/photos/${ctx.pageId}/${photoId}`, {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': ctx.restNonce,
					},
				});

				if (response.ok) {
					// Remove the photo from local state.
					state.photos = state.photos.filter((photo) => photo.id !== photoId);

					// Keep lightboxIndex valid: close the lightbox if the gallery
					// is now empty, or clamp the index if it now points past the end.
					if (state.lightboxIndex >= 0) {
						if (state.photos.length === 0) {
							state.lightboxIndex = -1;
						} else if (state.lightboxIndex >= state.photos.length) {
							state.lightboxIndex = state.photos.length - 1;
						}
					}
				} else {
					state.errorMessage = 'Failed to delete photo. Please try again.';
				}
			} catch {
				state.errorMessage = 'Network error. Please try again.';
			} finally {
				state.deletingPhotoId = null;
			}
		},

		/**
		 * Open the lightbox at the position of the clicked photo.
		 *
		 * Reads the photo from the data-wp-each item context, finds its
		 * position in state.photos, and stores that index. Storing the
		 * index (rather than a copy of the photo) keeps the lightbox in
		 * sync with the photos array if it mutates.
		 */
		openLightbox() {
			const ctx = getContext();
			if (!ctx.item) {
				return;
			}
			const idx = state.photos.findIndex((p) => p.id === ctx.item.id);
			if (idx < 0) {
				// The clicked photo is no longer in state.photos — it was likely
				// removed by a concurrent moderator deletion. Nothing to open.
				return;
			}
			state.lightboxIndex = idx;
		},

		/**
		 * Close the lightbox overlay.
		 *
		 * Clicks on the image itself or on the prev/next nav buttons must
		 * not close the overlay — only clicks on the overlay background
		 * or the explicit close button should. We use closest() to detect
		 * the controls regardless of which element the click bubbled up
		 * through.
		 *
		 * @param {Event} event The click event.
		 */
		closeLightbox(event) {
			// '.pixfete-lightbox-close' is intentionally absent — clicks on the
			// close button should propagate through to close the lightbox.
			if (event && event.target.closest('.pixfete-lightbox-image, .pixfete-lightbox-nav')) {
				return;
			}
			state.lightboxIndex = -1;
		},

		/**
		 * Navigate the lightbox to the previous photo.
		 *
		 * Stops at index 0 — there is no wrap-around. Stops event
		 * propagation so the click on the prev button does not also
		 * trigger the overlay's closeLightbox handler.
		 *
		 * @param {Event} [event] Optional click or keyboard event.
		 */
		prevPhoto(event) {
			event?.stopPropagation();
			if (state.lightboxIndex > 0) {
				state.lightboxIndex -= 1;
			}
		},

		/**
		 * Navigate the lightbox to the next photo.
		 *
		 * Stops at the last loaded photo — there is no wrap-around and
		 * no auto-trigger of "Load more". Stops event propagation so the
		 * click on the next button does not also trigger closeLightbox.
		 *
		 * @param {Event} [event] Optional click or keyboard event.
		 */
		nextPhoto(event) {
			event?.stopPropagation();
			if (state.lightboxIndex >= 0 && state.lightboxIndex < state.photos.length - 1) {
				state.lightboxIndex += 1;
			}
		},

		/**
		 * Capture the starting position of a touch on the lightbox overlay.
		 *
		 * @param {TouchEvent} event The touchstart event.
		 */
		lightboxTouchStart(event) {
			const t = event.touches?.[0];
			if (!t) {
				return;
			}
			lightboxTouchStartX = t.clientX;
			lightboxTouchStartY = t.clientY;
		},

		/**
		 * On touchend, decide whether the gesture was a horizontal swipe and,
		 * if so, navigate to the previous or next photo. Vertical-dominant
		 * gestures and short gestures (below SWIPE_THRESHOLD) are ignored so
		 * we don't fight with the user's intent to scroll or tap.
		 *
		 * @param {TouchEvent} event The touchend event.
		 */
		lightboxTouchEnd(event) {
			const t = event.changedTouches?.[0];
			if (!t) {
				return;
			}
			const dx = t.clientX - lightboxTouchStartX;
			const dy = t.clientY - lightboxTouchStartY;
			if (Math.abs(dx) < SWIPE_THRESHOLD || Math.abs(dx) < Math.abs(dy)) {
				return;
			}
			const { actions } = store('pixfete');
			if (dx < 0) {
				actions.nextPhoto();
			} else {
				actions.prevPhoto();
			}
		},

		/**
		 * Toggle the FAB expanded/collapsed state.
		 */
		toggleFab() {
			state.fabOpen = !state.fabOpen;
			if (state.fabOpen) {
				// Move focus to first sub-button after the DOM updates.
				window.requestAnimationFrame(() => {
					const firstBtn = document.querySelector('.pixfete-fab-menu .pixfete-fab-btn');
					if (firstBtn) {
						firstBtn.focus();
					}
				});
			}
		},

		/**
		 * Close the FAB menu.
		 */
		closeFab() {
			state.fabOpen = false;
			// Return focus to the main FAB button.
			const mainBtn = document.querySelector('.pixfete-fab-btn--main');
			if (mainBtn) {
				mainBtn.focus();
			}
		},

		/**
		 * Handle Escape key press to close the FAB.
		 *
		 * @param {KeyboardEvent} event The keydown event.
		 */
		handleFabKeydown(event) {
			if (event.key === 'Escape' && state.fabOpen) {
				const { actions } = store('pixfete');
				actions.closeFab();
			}
		},

		/**
		 * Trigger the camera file input (with capture attribute).
		 */
		triggerCapture() {
			state.fabOpen = false;
			document.getElementById('pixfete-file-capture')?.click();
		},

		/**
		 * Trigger the gallery file input (with multiple attribute).
		 */
		triggerGallery() {
			state.fabOpen = false;
			document.getElementById('pixfete-file-gallery')?.click();
		},
	},
});
