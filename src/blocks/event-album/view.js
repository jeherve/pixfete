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
import { enqueue, listPending, markDone, markFailed } from './upload-queue';

/**
 * Interpolate the `%d` placeholder in a translation template.
 *
 * View modules can't import `@wordpress/i18n` yet (script modules don't
 * support it), so translations are pre-rendered server-side in render.php
 * and passed via the `i18n` context. This helper handles the count
 * substitution that would otherwise be done by `sprintf()`.
 *
 * @param {string} template Translation template containing a `%d` token.
 * @param {number} count    Value to substitute for `%d`.
 * @return {string} Interpolated string.
 */
function formatCount(template, count) {
	return template.replace('%d', String(count));
}

/**
 * Substitute a single `%s` token in a translation template.
 *
 * @param {string} template Translation template containing a `%s` token.
 * @param {string} value    Value to substitute for `%s`.
 * @return {string} Interpolated string.
 */
function formatString(template, value) {
	return template.replace('%s', value);
}

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
 * The element that held focus before the lightbox opened. Stored at module
 * scope so we can restore focus when the lightbox closes — required for
 * dialogs marked with aria-modal so keyboard and screen reader users return
 * to the thumbnail they came from instead of being dropped on the body.
 */
let lightboxOpener = null;

/**
 * Restore focus to the element that opened the lightbox, if it is still in
 * the DOM. Called from every lightbox close path (overlay/close-button click,
 * Escape key, last photo deleted by a moderator).
 */
function restoreLightboxFocus() {
	if (
		lightboxOpener &&
		typeof lightboxOpener.focus === 'function' &&
		lightboxOpener.ownerDocument?.contains(lightboxOpener)
	) {
		lightboxOpener.focus();
	}
	lightboxOpener = null;
}

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
		/** Mirror of the IndexedDB upload queue for this page, freshest first. */
		pendingUploads: [],
		pollingId: 0,

		/** Whether the current user is an assigned moderator for this event. */
		isModerator: false,

		/** WordPress REST API nonce for authenticated requests (moderators only). */
		restNonce: '',

		/** The attachment ID currently being deleted, or null if idle. */
		deletingPhotoId: null,

		/** Whether the password field is currently shown in plain text. */
		passwordVisible: false,

		/**
		 * The `type` attribute for the password input.
		 *
		 * Bound to the input via `data-wp-bind--type` so the show/hide
		 * toggle can flip between masked and plain text without losing focus.
		 *
		 * @return {string} 'text' when revealed, 'password' otherwise.
		 */
		get passwordInputType() {
			return state.passwordVisible ? 'text' : 'password';
		},

		/**
		 * Accessible label for the password visibility toggle button.
		 *
		 * Strings are translated server-side and passed in via the
		 * Interactivity context so we don't need to load `@wordpress/i18n`
		 * inside the view module.
		 *
		 * @return {string} Localized label describing the next action.
		 */
		get passwordToggleLabel() {
			const ctx = getContext();
			return state.passwordVisible ? ctx.i18n.hidePasswordLabel : ctx.i18n.showPasswordLabel;
		},

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
			const ctx = getContext();
			const count = state.newPhotoCount;
			const template = count === 1 ? ctx.i18n.newPhotoBannerSingle : ctx.i18n.newPhotoBannerPlural;
			return formatCount(template, count);
		},

		/**
		 * Whether any queued upload has hit a permanent failure.
		 *
		 * Drives visibility of the "Retry uploads" affordance — we only
		 * surface the manual retry button when the in-page loop has given
		 * up so guests aren't tempted to spam-tap during normal retries.
		 *
		 * @return {boolean} True if any queued item is in 'failed' state.
		 */
		get hasFailedUploads() {
			return state.pendingUploads.some((item) => item.status === 'failed');
		},

		/**
		 * Localized "uploading" label for queued placeholder thumbnails.
		 *
		 * @return {string} Translated text from server-rendered i18n context.
		 */
		get queuedLabelText() {
			return getContext().i18n.queuedLabel;
		},

		/**
		 * Localized "failed" label for permanent-failure placeholder thumbnails.
		 *
		 * @return {string} Translated text from server-rendered i18n context.
		 */
		get failedLabelText() {
			return getContext().i18n.failedLabel;
		},

		/**
		 * Whether any upload is currently in flight or waiting to be tried.
		 *
		 * Used by the progress banner. Derived from the queue rather than a
		 * separate counter so the banner cannot drift out of sync with the
		 * actual work pending.
		 *
		 * @return {boolean} True when at least one queued upload exists.
		 */
		get isUploading() {
			return state.pendingUploads.length > 0;
		},

		/**
		 * Short status text for the upload progress banner.
		 *
		 * @return {string} Something like "📷 Uploading 3…".
		 */
		get uploadBannerText() {
			if (!state.pendingUploads.length) {
				return '';
			}
			return `\u{1f4f7} ${state.pendingUploads.length}\u2026`;
		},
	},

	actions: {
		/**
		 * Initialize the app state on mount.
		 *
		 * Reads the cookie, checks URL parameters, fetches a fresh CSRF
		 * token, and determines which view to show first. Doubles as the
		 * retry handler for the loading-view "Try again" button: clearing
		 * `errorMessage` at the top resets prior failure state, and
		 * `currentView` stays on 'loading' until we know where to send
		 * the user.
		 */
		*init() {
			state.errorMessage = '';

			// Restore any uploads queued on a previous visit so the user
			// can see (and we can resume) their pending work.
			try {
				state.pendingUploads = yield listPending(getContext().pageId);
			} catch {
				state.pendingUploads = [];
			}

			// Read URL parameters before cleaning. Stash on state so a
			// retry (which runs after URL params have been cleaned) still
			// behaves correctly.
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

			const ctx = getContext();

			// Sync moderator status from server-rendered context into
			// global state so data-wp-bind directives can read it.
			state.isModerator = ctx.isModerator ?? false;
			state.restNonce = ctx.restNonce ?? '';
			const cookie = readCookie(ctx.pageId);

			// Authenticated and consented — skip the token round-trip and
			// go straight to the gallery. Saves a request on the path most
			// returning visitors take.
			if (cookie && cookie.consent === true) {
				state.currentView = 'gallery';
				const { actions } = store('pixfete');
				if (state.pendingUploads.length) {
					actions.drainQueue();
				}
				actions.loadPhotos();
				actions.startPolling();
				return;
			}

			// Every remaining path needs a CSRF-protected POST, so fetch a
			// fresh token before transitioning. The HTML may be cached, so
			// we don't trust any token that came in via the context.
			try {
				const response = yield fetch(`${ctx.restBase}/token/${ctx.pageId}`, {
					credentials: 'same-origin',
				});
				if (!response.ok) {
					state.errorMessage = ctx.i18n.initFailed;
					return;
				}
				const data = yield response.json();
				ctx.nonce = data.nonce;
			} catch {
				state.errorMessage = ctx.i18n.initConnectionFailed;
				return;
			}

			if (cookie && cookie.consent === false) {
				// Valid cookie without consent — show consent screen.
				// Use the freshly fetched CSRF token as the consent nonce,
				// since the normal registration flow (which sets consentNonce) was skipped.
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
		 * Toggle whether the password field shows its value in plain text.
		 *
		 * Lets guests verify the password they typed without retyping it,
		 * which is especially helpful on mobile keyboards where mistypes are
		 * common and the password is being shared verbally on the day of the event.
		 */
		togglePasswordVisibility() {
			state.passwordVisible = !state.passwordVisible;
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
				state.errorMessage = getContext().i18n.passwordRequired;
				return;
			}

			state.isSubmitting = true;
			const ctx = getContext();

			try {
				// Two attempts: if the first fails with an invalid-nonce error
				// and the server hands us a fresh one, retry transparently
				// rather than surfacing a confusing CSRF error to the user.
				for (let attempt = 1; attempt <= 2; attempt++) {
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
						if (errorData.data?.nonce) {
							ctx.nonce = errorData.data.nonce;
						}
						if (attempt === 1 && errorData.code === 'pixfete_invalid_nonce' && errorData.data?.nonce) {
							continue;
						}
						state.errorMessage = errorData.message || ctx.i18n.passwordIncorrect;
						return;
					}

					const data = yield response.json();

					// Store the fresh nonce for the registration step.
					ctx.nonce = data.nonce;
					state.currentView = 'registration';
					return;
				}
			} catch {
				state.errorMessage = ctx.i18n.networkError;
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
				state.errorMessage = getContext().i18n.nameRequired;
				return;
			}

			state.isSubmitting = true;
			const ctx = getContext();

			try {
				for (let attempt = 1; attempt <= 2; attempt++) {
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
						if (errorData.data?.nonce) {
							ctx.nonce = errorData.data.nonce;
						}
						if (attempt === 1 && errorData.code === 'pixfete_invalid_nonce' && errorData.data?.nonce) {
							continue;
						}
						state.errorMessage = errorData.message || ctx.i18n.registrationFailed;
						return;
					}

					const data = yield response.json();
					state.consentNonce = data.consent_nonce || '';
					state.currentView = 'consent';
					return;
				}
			} catch {
				state.errorMessage = ctx.i18n.networkError;
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
				for (let attempt = 1; attempt <= 2; attempt++) {
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
						if (errorData.data?.nonce) {
							state.consentNonce = errorData.data.nonce;
						}
						if (attempt === 1 && errorData.code === 'pixfete_invalid_nonce' && errorData.data?.nonce) {
							continue;
						}
						state.errorMessage = errorData.message || ctx.i18n.consentFailed;
						return;
					}

					state.currentView = 'gallery';

					const { actions } = store('pixfete');
					actions.loadPhotos();
					if (state.pendingUploads.length) {
						actions.drainQueue();
					}
					actions.startPolling();
					return;
				}
			} catch {
				state.errorMessage = ctx.i18n.networkError;
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
					state.errorMessage = ctx.i18n.loadPhotosFailed;
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
				state.errorMessage = ctx.i18n.loadPhotosFailed;
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
		 * Persist selected files to the IndexedDB queue and kick off the drain.
		 *
		 * Selection used to upload inline; if the page closed mid-batch or
		 * the network blipped, photos were lost. Now we persist first and
		 * upload from the queue, so recovery is automatic and the SW
		 * Background Sync handler (registered separately) can also pick up
		 * stragglers after the tab closes.
		 *
		 * @param {Event} event The change event from the file input.
		 */
		*handleFileSelect(event) {
			const files = event.target.files;
			if (!files || files.length === 0) {
				return;
			}
			const ctx = getContext();

			for (const file of files) {
				yield enqueue({ pageId: ctx.pageId, blob: file, name: file.name });
			}

			state.pendingUploads = yield listPending(ctx.pageId);

			// Reset the input so the same file can be selected again later.
			event.target.value = '';

			const { actions } = store('pixfete');
			yield actions.drainQueue();
		},

		/**
		 * Sequentially upload pending queue items to the REST endpoint.
		 *
		 * Stops on the first failure rather than draining-around it: if one
		 * upload is failing we'd rather surface that quickly than burn the
		 * remaining files into the same failure mode. The SW retry path
		 * picks up where this one left off.
		 *
		 * @return {Promise<void>}
		 */
		async drainQueue() {
			const ctx = getContext();
			let pending = await listPending(ctx.pageId);

			while (pending.length > 0) {
				const item = pending[0];
				try {
					const formData = new FormData();
					formData.append('photo', item.blob, item.name);

					const response = await fetch(`${ctx.restBase}/photos/${ctx.pageId}`, {
						method: 'POST',
						credentials: 'same-origin',
						body: formData,
					});

					if (!response.ok) {
						await markFailed(item.id, 'http');
						state.pendingUploads = await listPending(ctx.pageId);
						return;
					}

					const photo = await response.json();
					await markDone(item.id);
					state.photos = [photo, ...state.photos];
					if (photo.uploaded_at && photo.uploaded_at > state.latestUploadedAt) {
						state.latestUploadedAt = photo.uploaded_at;
					}
				} catch {
					await markFailed(item.id, 'network');
					state.pendingUploads = await listPending(ctx.pageId);
					return;
				}

				pending = await listPending(ctx.pageId);
				state.pendingUploads = pending;
			}
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
			const confirmMessage = formatString(ctx.i18n.confirmDeletePhoto, guestName);
			// eslint-disable-next-line no-alert -- Intentional use of confirm for destructive action.
			const confirmed = window.confirm(confirmMessage);

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
							restoreLightboxFocus();
						} else if (state.lightboxIndex >= state.photos.length) {
							state.lightboxIndex = state.photos.length - 1;
						}
					}
				} else {
					state.errorMessage = ctx.i18n.deletePhotoFailed;
				}
			} catch {
				state.errorMessage = ctx.i18n.deleteNetworkError;
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

			// Capture the element that triggered the open so we can restore
			// focus on close. activeElement is normally the .pixfete-photo
			// thumbnail, but tolerate the unlikely null/non-Element case.
			// Use the dialog's ownerDocument (rather than the global
			// document) so the lookup is correct in iframed contexts.
			const lightboxEl = document.querySelector('.pixfete-lightbox');
			const candidate = lightboxEl?.ownerDocument.activeElement;
			lightboxOpener = candidate && typeof candidate.focus === 'function' ? candidate : null;

			state.lightboxIndex = idx;

			// Move focus into the dialog after Interactivity API renders it
			// visible. Close is the safest target — always present, never
			// disabled, regardless of which photo is shown.
			window.requestAnimationFrame(() => {
				document.querySelector('.pixfete-lightbox-close')?.focus();
			});
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
			restoreLightboxFocus();
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

	callbacks: {
		/**
		 * Bind a window-level keydown listener for lightbox navigation.
		 *
		 * Triggered by data-wp-init on the lightbox root. Uses a global
		 * sentinel so the listener is bound only once even if the block
		 * appears multiple times on the page or the init callback fires
		 * more than once during hydration.
		 *
		 * Only acts when the lightbox is open so arrow keys keep their
		 * default browser behavior the rest of the time. ArrowLeft and
		 * ArrowRight call preventDefault() to suppress the default page
		 * scroll while navigating photos.
		 */
		initLightboxKeyboard() {
			if (window.__pixfeteLightboxKeyboardBound) {
				return;
			}
			window.__pixfeteLightboxKeyboardBound = true;

			window.addEventListener('keydown', (event) => {
				if (!state.lightboxOpen) {
					return;
				}
				if (event.key === 'ArrowLeft') {
					event.preventDefault();
					state.lightboxIndex = Math.max(0, state.lightboxIndex - 1);
				} else if (event.key === 'ArrowRight') {
					event.preventDefault();
					state.lightboxIndex = Math.min(state.photos.length - 1, state.lightboxIndex + 1);
				} else if (event.key === 'Escape') {
					state.lightboxIndex = -1;
					restoreLightboxFocus();
				} else if (event.key === 'Tab') {
					// Trap focus inside the dialog while it is open. Re-query
					// each Tab press so disabled prev/next buttons (at the
					// boundaries) are correctly excluded from the cycle.
					const dialog = document.querySelector('.pixfete-lightbox');
					if (!dialog) {
						return;
					}
					const focusables = Array.from(dialog.querySelectorAll('button:not([disabled])'));
					if (focusables.length === 0) {
						return;
					}
					const idx = focusables.indexOf(dialog.ownerDocument.activeElement);
					if (idx === -1) {
						// Focus has escaped (or landed on a now-disabled nav
						// button) — pull it back to the dialog.
						event.preventDefault();
						focusables[0].focus();
						return;
					}
					if (event.shiftKey && idx === 0) {
						event.preventDefault();
						focusables[focusables.length - 1].focus();
					} else if (!event.shiftKey && idx === focusables.length - 1) {
						event.preventDefault();
						focusables[0].focus();
					}
				}
			});
		},
	},
});
