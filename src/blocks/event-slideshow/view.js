/**
 * Frontend view module for the event-slideshow block.
 *
 * Registers an Interactivity API store that drives the full-screen slideshow
 * used for projecting event photos. Handles password auth, photo loading,
 * polling for new submissions, and crossfade transitions between photos.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/
 */

import './view.scss';
import { store, getContext } from '@wordpress/interactivity';

/**
 * Number of photos to fetch per REST API request.
 *
 * @type {number}
 */
const PER_PAGE = 100;

/**
 * Milliseconds between poll requests for new photo submissions.
 *
 * @type {number}
 */
const POLL_INTERVAL = 5000;

/**
 * Duration in milliseconds for crossfade transitions between photos.
 *
 * @type {number}
 */
const FADE_DURATION = 1000;

/**
 * Maximum number of photos to eagerly load across all pages.
 * Beyond this limit, older pages are skipped to conserve memory.
 *
 * @type {number}
 */
const MAX_EAGER_PHOTOS = 500;

/**
 * Number of consecutive network failures before switching to backoff polling.
 *
 * @type {number}
 */
const MAX_CONSECUTIVE_FAILURES = 5;

/**
 * Milliseconds between poll requests when in backoff mode after repeated failures.
 *
 * @type {number}
 */
const BACKOFF_INTERVAL = 30000;

/**
 * Read and decode the HMAC-signed cookie for a given event page.
 *
 * @param {number} eventPageId The event page ID.
 * @return {Object|null} Decoded cookie payload, or null.
 */
function readCookie(eventPageId) {
	const name = `pixfete_${eventPageId}=`;
	const cookies = document.cookie.split('; ');
	for (const cookie of cookies) {
		if (cookie.startsWith(name)) {
			try {
				const value = cookie.substring(name.length);
				const dotIndex = value.indexOf('.');
				if (dotIndex === -1) {
					return null;
				}
				const base64Url = value.substring(0, dotIndex);
				const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
				return JSON.parse(atob(base64));
			} catch {
				return null;
			}
		}
	}
	return null;
}

const { state } = store('pixfete/slideshow', {
	state: {
		currentView: 'loading',
		passwordInput: '',
		isSubmitting: false,
		errorMessage: '',
		photos: [],
		currentIndex: 0,
		currentPhoto: null,
		nextPhoto: null,
		isFading: false,
		isWaiting: true,
		latestUploadedAt: 0,
		pollingId: null,
		advanceId: null,
		consecutiveFailures: 0,
		totalPages: 0,

		get isLoadingView() {
			return state.currentView === 'loading';
		},
		get isNotStartedView() {
			return state.currentView === 'not-started';
		},
		get isPasswordView() {
			return state.currentView === 'password';
		},
		get isSlideshowView() {
			return state.currentView === 'slideshow';
		},
		get isEventStarted() {
			const ctx = getContext();
			if (!ctx.dateStart) {
				return true;
			}
			const today = new Date().toISOString().substring(0, 10);
			return today >= ctx.dateStart;
		},
		get currentPhotoFull() {
			return state.currentPhoto?.full || '';
		},
		get currentPhotoAlt() {
			return state.currentPhoto?.guest_name || '';
		},
		get currentPhotoGuestName() {
			return state.currentPhoto?.guest_name || '';
		},
		get currentPhotoTableName() {
			return state.currentPhoto?.table_name || '';
		},
		get nextPhotoFull() {
			return state.nextPhoto?.full || '';
		},
		get nextPhotoAlt() {
			return state.nextPhoto?.guest_name || '';
		},
	},

	actions: {
		init() {
			if (!state.isEventStarted) {
				state.currentView = 'not-started';
				return;
			}

			const ctx = getContext();
			const cookie = readCookie(ctx.eventPageId);
			if (cookie && cookie.consent === true && cookie.event_version === (ctx.eventVersion ?? 1)) {
				state.currentView = 'slideshow';
				actions.loadPhotos();
				actions.startPolling();
				return;
			}

			state.currentView = 'password';
		},

		updatePasswordInput(event) {
			state.passwordInput = event.target.value;
		},

		*submitPassword(event) {
			event.preventDefault();
			const ctx = getContext();

			state.isSubmitting = true;
			state.errorMessage = '';

			try {
				const response = yield fetch(`${ctx.restBase}/auth/${ctx.eventPageId}`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Pixfete-Nonce': ctx.nonce,
					},
					credentials: 'same-origin',
					body: JSON.stringify({
						action: 'slideshow_auth',
						password: state.passwordInput,
						[ctx.honeypotField]: '',
					}),
				});

				const data = yield response.json();

				if (!response.ok) {
					if (data?.data?.nonce) {
						ctx.nonce = data.data.nonce;
					}
					state.errorMessage = data?.message || 'The password is incorrect.';
					return;
				}

				ctx.nonce = data.nonce;
				state.currentView = 'slideshow';
				actions.loadPhotos();
				actions.startPolling();
			} catch {
				state.errorMessage = 'A network error occurred.';
			} finally {
				state.isSubmitting = false;
			}
		},

		*loadPhotos() {
			const ctx = getContext();

			try {
				const url = new URL(`${ctx.restBase}/photos/${ctx.eventPageId}`);
				url.searchParams.set('per_page', PER_PAGE);
				url.searchParams.set('page', '1');

				const response = yield fetch(url, {
					credentials: 'same-origin',
				});

				if (response.status === 401 || response.status === 403) {
					state.currentView = 'password';
					if (state.advanceId) {
						clearInterval(state.advanceId);
						state.advanceId = null;
					}
					return;
				}

				const photos = yield response.json();
				state.totalPages = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);

				if (photos.length === 0) {
					state.isWaiting = true;
					return;
				}

				state.photos = photos;
				state.currentPhoto = photos[0];
				state.currentIndex = 0;
				state.isWaiting = false;
				state.latestUploadedAt = Math.max(...photos.map((p) => p.uploaded_at));

				actions.startAdvancing();

				if (state.totalPages > 1) {
					actions.loadRemainingPages();
				}
			} catch {
				// Silent failure — polling will retry.
			}
		},

		*loadRemainingPages() {
			const ctx = getContext();
			const maxPages = Math.min(state.totalPages, Math.ceil(MAX_EAGER_PHOTOS / PER_PAGE));

			for (let page = 2; page <= maxPages; page++) {
				try {
					const pageUrl = new URL(`${ctx.restBase}/photos/${ctx.eventPageId}`);
					pageUrl.searchParams.set('per_page', PER_PAGE);
					pageUrl.searchParams.set('page', page);

					const response = yield fetch(pageUrl, { credentials: 'same-origin' });
					const photos = yield response.json();
					state.photos = [...state.photos, ...photos];

					const maxUploadedAt = Math.max(...photos.map((p) => p.uploaded_at));
					if (maxUploadedAt > state.latestUploadedAt) {
						state.latestUploadedAt = maxUploadedAt;
					}
				} catch {
					break;
				}
			}
		},

		startPolling() {
			if (state.pollingId) {
				return;
			}

			const ctx = getContext();
			const eventPageId = ctx.eventPageId;
			const restBase = ctx.restBase;

			const poll = async () => {
				try {
					const pollUrl = new URL(`${restBase}/photos/${eventPageId}`);
					pollUrl.searchParams.set('since', state.latestUploadedAt);
					pollUrl.searchParams.set('per_page', PER_PAGE);

					const response = await fetch(pollUrl, { credentials: 'same-origin' });

					if (response.status === 401 || response.status === 403) {
						state.currentView = 'password';
						clearInterval(state.pollingId);
						state.pollingId = null;
						if (state.advanceId) {
							clearInterval(state.advanceId);
							state.advanceId = null;
						}
						return;
					}

					if (!response.ok) {
						throw new Error('Poll failed');
					}

					const newPhotos = await response.json();

					// If we were in backoff mode, restart polling at the normal rate.
					const wasBackingOff = state.consecutiveFailures > 0;
					state.consecutiveFailures = 0;
					if (wasBackingOff && state.pollingId) {
						clearInterval(state.pollingId);
						state.pollingId = setInterval(poll, POLL_INTERVAL);
					}

					if (newPhotos.length === 0) {
						return;
					}

					const existingIds = new Set(state.photos.map((p) => p.id));
					const uniquePhotos = newPhotos.filter((p) => !existingIds.has(p.id));

					if (uniquePhotos.length === 0) {
						return;
					}

					state.photos = [...state.photos, ...uniquePhotos];
					state.latestUploadedAt = Math.max(
						state.latestUploadedAt,
						...uniquePhotos.map((p) => p.uploaded_at)
					);

					if (state.isWaiting) {
						state.currentPhoto = state.photos[0];
						state.currentIndex = 0;
						state.isWaiting = false;
						actions.startAdvancing();
					}
				} catch {
					state.consecutiveFailures++;

					if (state.consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
						clearInterval(state.pollingId);
						state.pollingId = null;
						state.pollingId = setInterval(poll, BACKOFF_INTERVAL);
					}
				}
			};

			state.pollingId = setInterval(poll, POLL_INTERVAL);
		},

		startAdvancing() {
			if (state.advanceId) {
				return;
			}

			const ctx = getContext();
			const intervalMs = (ctx.interval || 5) * 1000;

			state.advanceId = setInterval(() => {
				if (state.currentIndex >= state.photos.length - 1) {
					return;
				}

				const nextIndex = state.currentIndex + 1;
				state.nextPhoto = state.photos[nextIndex];
				state.isFading = true;

				setTimeout(() => {
					state.currentPhoto = state.nextPhoto;
					state.currentIndex = nextIndex;
					state.isFading = false;
					state.nextPhoto = null;
				}, FADE_DURATION);
			}, intervalMs);
		},
	},
});

// Forward reference: `actions` is used inside init(), submitPassword(), etc.
// This works because those functions are only invoked after module load completes,
// at which point this destructuring has already run.
const { actions } = store('pixfete/slideshow');
