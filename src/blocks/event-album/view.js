/**
 * Frontend Interactivity API store for the Event Photo Album block.
 *
 * Manages the full guest experience: password entry, registration,
 * consent, photo gallery, uploads, polling, and lightbox.
 *
 * @package
 */

import './view.scss';

import { store, getContext, getElement } from '@wordpress/interactivity';

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
 * Read and decode the EGPS cookie for a given page ID.
 *
 * The cookie format is `{base64url-encoded JSON}.{HMAC}`. We only need
 * the payload portion (HMAC verification happens server-side).
 *
 * @param {number} pageId The WordPress page ID.
 * @return {Object|null} Decoded cookie payload, or null if not found/invalid.
 */
function readCookie(pageId) {
	const name = `egps_${pageId}=`;
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

const { state } = store('event-guest-photos-sharing', {
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
		consentHtml: '',
		lightboxOpen: false,
		lightboxPhoto: { full: '', guest_name: '' },
		latestUploadedAt: 0,
		pollingId: 0,

		/**
		 * Whether the current view is the loading view.
		 *
		 * @return {boolean} True if showing loading state.
		 */
		get isLoadingView() {
			return state.currentView === 'loading';
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
		 * Whether photo uploads are currently enabled based on the event date range.
		 *
		 * Checks the dateStart and dateEnd from the block context against the
		 * current date. If no dates are set, uploads are always enabled.
		 *
		 * @return {boolean} True if uploads are allowed.
		 */
		get isUploadEnabled() {
			const ctx = getContext();
			const today = new Date().toISOString().substring(0, 10);

			if (ctx.dateStart && today < ctx.dateStart) {
				return false;
			}
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
	},

	actions: {
		/**
		 * Initialize the app state on mount.
		 *
		 * Reads the cookie, checks URL parameters, and determines
		 * which view to show first.
		 */
		init() {
			const ctx = getContext();
			const el = getElement();

			// Read the consent message from the template element.
			const consentTemplate = el.ref.querySelector('.egps-consent-message');
			if (consentTemplate) {
				state.consentHtml = consentTemplate.innerHTML;
			}

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

			// Read the cookie to determine initial state.
			const cookie = readCookie(ctx.pageId);

			if (cookie && cookie.consent === true) {
				// Valid cookie with consent — go to gallery.
				state.currentView = 'gallery';
				// Start loading photos and polling.
				const { actions } = store('event-guest-photos-sharing');
				actions.loadPhotos();
				actions.startPolling();
			} else if (cookie && cookie.consent === false) {
				// Valid cookie without consent — show consent screen.
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
						'X-EGPS-Nonce': ctx.nonce,
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
						'X-EGPS-Nonce': ctx.nonce,
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
						'X-EGPS-Nonce': state.consentNonce,
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

				const { actions } = store('event-guest-photos-sharing');
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
			const { actions } = store('event-guest-photos-sharing');
			yield* actions.loadPhotos();
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
		 * Prepend pending photos to the gallery and clear the banner.
		 */
		showNewPhotos() {
			// Deduplicate pending photos against the current gallery.
			const existingIds = new Set(state.photos.map((p) => p.id));
			const unique = state.pendingPhotos.filter((p) => !existingIds.has(p.id));
			state.photos = [...unique, ...state.photos];
			state.pendingPhotos = [];
			state.newPhotoCount = 0;
		},

		/**
		 * Handle file selection for photo uploads.
		 *
		 * Uploads each selected file individually via the REST endpoint.
		 * On success, prepends the new photo to the gallery.
		 *
		 * @param {Event} event The change event from the file input.
		 */
		*handleFileSelect(event) {
			const files = event.target.files;
			if (!files || files.length === 0) {
				return;
			}

			state.errorMessage = '';
			const ctx = getContext();

			for (const file of files) {
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
						state.errorMessage = errorData.message || 'Upload failed. Please try again.';
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
					state.errorMessage = 'Upload failed. Please check your connection and try again.';
				}
			}

			// Reset the file input so the same file can be selected again.
			event.target.value = '';
		},

		/**
		 * Open the lightbox with the clicked photo.
		 *
		 * Reads the photo data from the `data-wp-each` item context.
		 */
		openLightbox() {
			const ctx = getContext();
			if (ctx.item) {
				state.lightboxPhoto = {
					full: ctx.item.full,
					guest_name: ctx.item.guest_name,
				};
				state.lightboxOpen = true;
			}
		},

		/**
		 * Close the lightbox overlay.
		 *
		 * Prevents closing when clicking the image itself (only the
		 * overlay background or close button should close it).
		 *
		 * @param {Event} event The click event.
		 */
		closeLightbox(event) {
			// Only close when clicking the overlay or close button,
			// not when clicking the image.
			if (event.target.tagName === 'IMG' && !event.target.classList.contains('egps-lightbox-close')) {
				return;
			}
			state.lightboxOpen = false;
			state.lightboxPhoto = { full: '', guest_name: '' };
		},
	},
});
