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
