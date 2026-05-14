/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

import { initInstallPrompt, resetForTests } from '../install-prompt';

beforeEach(() => {
	resetForTests();
});

function fireBeforeInstallPrompt(prompt = jest.fn(() => Promise.resolve({ outcome: 'dismissed' }))) {
	const event = new Event('beforeinstallprompt');
	event.preventDefault = jest.fn();
	event.prompt = prompt;
	window.dispatchEvent(event);
	return event;
}

function setUserAgentData(value) {
	Object.defineProperty(navigator, 'userAgentData', {
		configurable: true,
		value,
	});
}

function setMatchMedia(matches) {
	window.matchMedia = jest.fn().mockImplementation((query) => ({
		matches,
		media: query,
		onchange: null,
		addListener: jest.fn(),
		removeListener: jest.fn(),
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
		dispatchEvent: jest.fn(),
	}));
}

function setInnerWidth(width) {
	Object.defineProperty(window, 'innerWidth', {
		configurable: true,
		value: width,
	});
}

describe('install-prompt', () => {
	test('captures beforeinstallprompt and calls preventDefault', () => {
		initInstallPrompt({ getFirstUploadDone: () => false, postId: 1, cookiePath: '/' });
		const event = fireBeforeInstallPrompt();
		expect(event.preventDefault).toHaveBeenCalledTimes(1);
	});

	test('exposes maybeShowPrompt as a callable', () => {
		const api = initInstallPrompt({ getFirstUploadDone: () => false, postId: 1, cookiePath: '/' });
		expect(typeof api.maybeShowPrompt).toBe('function');
		// Calling it before any beforeinstallprompt event is a no-op (resolves cleanly).
		return expect(api.maybeShowPrompt()).resolves.toBeUndefined();
	});
});

describe('install-prompt — mobile detection', () => {
	afterEach(() => {
		setUserAgentData(undefined);
	});

	test('does not call prompt() on desktop (userAgentData.mobile=false)', async () => {
		setUserAgentData({ mobile: false });
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 1, cookiePath: '/' });
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);
		await api.maybeShowPrompt();
		expect(promptFn).not.toHaveBeenCalled();
	});

	test('calls prompt() on mobile (userAgentData.mobile=true)', async () => {
		setUserAgentData({ mobile: true });
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 1, cookiePath: '/' });
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);
		await api.maybeShowPrompt();
		expect(promptFn).toHaveBeenCalledTimes(1);
	});

	test('falls back to pointer:coarse + narrow viewport when userAgentData is unavailable', async () => {
		setUserAgentData(undefined);
		setMatchMedia(true);
		setInnerWidth(400);
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 1, cookiePath: '/' });
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);
		await api.maybeShowPrompt();
		expect(promptFn).toHaveBeenCalledTimes(1);
	});

	test('fallback rejects wide viewports even with coarse pointer (tablet/desktop with touch)', async () => {
		setUserAgentData(undefined);
		setMatchMedia(true);
		setInnerWidth(1200);
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 1, cookiePath: '/' });
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);
		await api.maybeShowPrompt();
		expect(promptFn).not.toHaveBeenCalled();
	});
});

function setCookie(value) {
	Object.defineProperty(document, 'cookie', {
		configurable: true,
		value,
	});
}

function readWrittenCookies() {
	// jsdom's cookie jar isn't writable in the same way as a real browser; we
	// track Set-Cookie attempts by intercepting `document.cookie =`. To do that
	// cleanly, replace the descriptor with a setter spy.
	const calls = [];
	Object.defineProperty(document, 'cookie', {
		configurable: true,
		set(value) {
			calls.push(value);
		},
		get() {
			return '';
		},
	});
	return calls;
}

describe('install-prompt — dismissal cookie', () => {
	test('does not call prompt() when dismissal cookie is set for this post', async () => {
		setUserAgentData({ mobile: true });
		setCookie('pixfete_pwa_dismissed_42=1');
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 42, cookiePath: '/' });
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);
		await api.maybeShowPrompt();
		expect(promptFn).not.toHaveBeenCalled();
	});

	test('writes dismissal cookie on dismissed outcome', async () => {
		setUserAgentData({ mobile: true });
		const calls = readWrittenCookies();
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 42, cookiePath: '/blog/' });
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'dismissed' }));
		fireBeforeInstallPrompt(promptFn);
		await api.maybeShowPrompt();
		expect(calls.length).toBeGreaterThanOrEqual(1);
		const cookie = calls.find((c) => c.startsWith('pixfete_pwa_dismissed_42='));
		expect(cookie).toBeDefined();
		expect(cookie).toContain('path=/blog/');
		expect(cookie).toContain('max-age=');
		expect(cookie).toContain('SameSite=Lax');
	});

	test('does not write a dismissal cookie on accepted outcome', async () => {
		setUserAgentData({ mobile: true });
		const calls = readWrittenCookies();
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 42, cookiePath: '/' });
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);
		await api.maybeShowPrompt();
		const cookie = calls.find((c) => c.startsWith('pixfete_pwa_dismissed_42='));
		expect(cookie).toBeUndefined();
	});
});

describe('install-prompt — first-upload gate + session guard', () => {
	test('does not call prompt() until getFirstUploadDone returns true', async () => {
		setUserAgentData({ mobile: true });
		let uploadDone = false;
		const api = initInstallPrompt({
			getFirstUploadDone: () => uploadDone,
			postId: 1,
			cookiePath: '/',
		});
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);

		await api.maybeShowPrompt();
		expect(promptFn).not.toHaveBeenCalled();

		uploadDone = true;
		await api.maybeShowPrompt();
		expect(promptFn).toHaveBeenCalledTimes(1);
	});

	test('calls prompt() at most once even with repeated invocations', async () => {
		setUserAgentData({ mobile: true });
		const api = initInstallPrompt({
			getFirstUploadDone: () => true,
			postId: 1,
			cookiePath: '/',
		});
		const promptFn = jest.fn(() => Promise.resolve({ outcome: 'accepted' }));
		fireBeforeInstallPrompt(promptFn);

		await api.maybeShowPrompt();
		await api.maybeShowPrompt();
		await api.maybeShowPrompt();

		expect(promptFn).toHaveBeenCalledTimes(1);
	});
});

describe('install-prompt — error handling', () => {
	// Callers in view.js invoke maybeShowPrompt() fire-and-forget, so a rejected
	// prompt() must not propagate as an unhandled rejection. The module also
	// shouldn't write a dismissal cookie when the call fails — the user hasn't
	// actually said "no".
	test('swallows prompt() rejection without throwing or writing a cookie', async () => {
		setUserAgentData({ mobile: true });
		const calls = readWrittenCookies();
		const api = initInstallPrompt({ getFirstUploadDone: () => true, postId: 42, cookiePath: '/' });
		const promptFn = jest.fn(() => Promise.reject(new Error('install machinery failed')));
		fireBeforeInstallPrompt(promptFn);

		await expect(api.maybeShowPrompt()).resolves.toBeUndefined();
		expect(promptFn).toHaveBeenCalledTimes(1);
		const cookie = calls.find((c) => c.startsWith('pixfete_pwa_dismissed_42='));
		expect(cookie).toBeUndefined();
	});
});
