/**
 * Install-prompt module for the event-album block.
 *
 * Captures the browser's `beforeinstallprompt` event so we can choose
 * the moment to call `prompt()` ourselves — instead of letting Chrome
 * decide. The actual call is gated on (1) mobile detection,
 * (2) the per-event dismissal cookie, and (3) the guest having
 * completed their first upload. Those gates are added in subsequent
 * commits; this skeleton just wires the event capture.
 */

let deferredPrompt = null;
let promptShown = false;
let listenerInstalled = false;

/**
 * Capture handler for `beforeinstallprompt`.
 *
 * Calling `preventDefault` here suppresses the browser's own mini
 * infobar so we can render (or trigger) the prompt ourselves at a
 * moment of our choosing.
 *
 * @param {Event} event Browser-fired event with a `prompt()` method.
 */
function onBeforeInstallPrompt(event) {
	event.preventDefault();
	deferredPrompt = event;
}

/**
 * Whether the current device should be offered the install prompt.
 *
 * Pixfête's install moment is mobile-only (event photo uploads are
 * a mobile workflow), so desktop visitors should never see it.
 *
 * Detection order:
 *   1. `navigator.userAgentData.mobile` — modern Chromium-based
 *      browsers ship this and it's the most accurate signal.
 *   2. `matchMedia('(pointer: coarse)').matches && innerWidth < 900` —
 *      catches mobile Chromium without UA-Data and proxies "phone, not
 *      tablet" via viewport width. Touch-laptops can still trigger this
 *      with a wide viewport; the width guard keeps them out.
 *
 * @return {boolean} True when we should consider showing the prompt.
 */
function isMobile() {
	if (typeof navigator === 'undefined') {
		return false;
	}
	if (navigator.userAgentData && typeof navigator.userAgentData.mobile === 'boolean') {
		return navigator.userAgentData.mobile;
	}
	if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
		return false;
	}
	const coarse = window.matchMedia('(pointer: coarse)').matches;
	const narrow = (window.innerWidth || 0) < 900;
	return coarse && narrow;
}

const DISMISSAL_TTL_SECONDS = 30 * 24 * 60 * 60;

/**
 * Build the dismissal-cookie name for a given event post.
 *
 * Scoped by post ID so dismissing the prompt on one event doesn't
 * silence it on a separate event hosted on the same site.
 *
 * @param {number} postId Event-album post ID.
 * @return {string} Cookie name.
 */
function dismissalCookieName(postId) {
	return `pixfete_pwa_dismissed_${postId}`;
}

/**
 * Read the dismissal cookie for the current post.
 *
 * @param {number} postId Event-album post ID.
 * @return {boolean} True when the cookie is present with any value.
 */
function isDismissed(postId) {
	if (typeof document === 'undefined') {
		return false;
	}
	const name = dismissalCookieName(postId);
	const cookies = (document.cookie || '').split(';').map((c) => c.trim());
	return cookies.some((c) => c.startsWith(`${name}=`));
}

/**
 * Persist the dismissal cookie so we don't re-prompt this guest.
 *
 * @param {number} postId     Event-album post ID.
 * @param {string} cookiePath Cookie path matching `Cookie::cookie_path()`.
 */
function writeDismissal(postId, cookiePath) {
	if (typeof document === 'undefined') {
		return;
	}
	const name = dismissalCookieName(postId);
	const path = cookiePath || '/';
	const secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';
	document.cookie = `${name}=1; path=${path}; max-age=${DISMISSAL_TTL_SECONDS}; SameSite=Lax${secure}`;
}

/**
 * Wire up the install-prompt module.
 *
 * Returns a small API the block's view module can drive. The module
 * is gated on mobile detection and a per-event dismissal cookie;
 * `getFirstUploadDone` is wired through the signature now so the
 * upcoming first-upload gate can land as a pure logic addition.
 *
 * @param {Object}   options                    Module configuration.
 * @param {Function} options.getFirstUploadDone Callback returning whether the guest has uploaded.
 * @param {number}   options.postId             Event-album post ID (used for cookie scoping).
 * @param {string}   options.cookiePath         Cookie path matching `Cookie::cookie_path()`.
 * @return {{ maybeShowPrompt: Function }} Public API.
 */
// eslint-disable-next-line no-unused-vars
export function initInstallPrompt({ getFirstUploadDone, postId, cookiePath } = {}) {
	if (typeof window === 'undefined') {
		return { maybeShowPrompt: async () => undefined };
	}
	if (!listenerInstalled) {
		window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
		listenerInstalled = true;
	}
	return {
		maybeShowPrompt: async () => {
			if (!deferredPrompt || promptShown) {
				return;
			}
			if (!isMobile()) {
				return;
			}
			if (typeof postId === 'number' && isDismissed(postId)) {
				return;
			}
			promptShown = true;
			const event = deferredPrompt;
			deferredPrompt = null;
			const result = await event.prompt();
			if (result && result.outcome === 'dismissed' && typeof postId === 'number') {
				writeDismissal(postId, cookiePath);
			}
		},
	};
}

/**
 * Reset module state between tests.
 *
 * Not part of the public API — exported so Jest can isolate test
 * cases without leaking captured events or the "already shown" flag.
 */
export function resetForTests() {
	if (typeof window !== 'undefined' && listenerInstalled) {
		window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
	}
	deferredPrompt = null;
	promptShown = false;
	listenerInstalled = false;
}
