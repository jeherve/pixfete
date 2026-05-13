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

/**
 * Wire up the install-prompt module.
 *
 * The `options` argument is accepted but unused in this skeleton —
 * subsequent commits add the gates (mobile detection, dismissal
 * cookie, first-upload signal) that consume `getFirstUploadDone`,
 * `postId`, and `cookiePath`.
 *
 * @param {Object}   _options                    Module configuration (reserved for upcoming gates).
 * @param {Function} _options.getFirstUploadDone Callback returning whether the guest has uploaded.
 * @param {number}   _options.postId             Event-album post ID (used for cookie scoping).
 * @param {string}   _options.cookiePath         Cookie path matching `Cookie::cookie_path()`.
 * @return {{ maybeShowPrompt: Function }} Public API.
 */
// eslint-disable-next-line no-unused-vars
export function initInstallPrompt(_options) {
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
			promptShown = true;
			const event = deferredPrompt;
			deferredPrompt = null;
			await event.prompt();
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
