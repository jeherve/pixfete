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
			// Gates added in subsequent tasks. For now: do nothing if no event
			// was captured or we've already shown the prompt this session.
			if (!deferredPrompt || promptShown) {
				// eslint-disable-next-line no-useless-return
				return;
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
