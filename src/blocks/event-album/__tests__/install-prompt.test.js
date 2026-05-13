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
