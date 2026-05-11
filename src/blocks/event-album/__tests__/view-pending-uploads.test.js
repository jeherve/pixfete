/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

import 'fake-indexeddb/auto';

const i18nFixture = require('../__fixtures__/i18n');

let registeredStore = {};
let mockContext = {};
jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			registeredStore = definition;
			return definition;
		},
		getContext: () => mockContext,
	}),
	{ virtual: true }
);

beforeEach(() => {
	registeredStore = {};
	mockContext = {
		pageId: 42,
		restBase: '/wp-json/pixfete/v1',
		dateStart: '',
		dateEnd: '',
		i18n: { ...i18nFixture, queuedLabel: 'Queued', failedLabel: 'Upload failed' },
	};
	jest.resetModules();
});

function loadStore() {
	require('../view');
	return registeredStore;
}

describe('pending uploads state', () => {
	test('pendingUploads defaults to empty', () => {
		const def = loadStore();
		expect(def.state.pendingUploads).toEqual([]);
	});

	test('hasFailedUploads is false when none failed', () => {
		const def = loadStore();
		def.state.pendingUploads = [{ id: 1, status: 'pending' }];
		expect(def.state.hasFailedUploads).toBe(false);
	});

	test('hasFailedUploads is true when any failed', () => {
		const def = loadStore();
		def.state.pendingUploads = [
			{ id: 1, status: 'pending' },
			{ id: 2, status: 'failed' },
		];
		expect(def.state.hasFailedUploads).toBe(true);
	});

	test('isUploading is false when only failed items remain', () => {
		// Regression: the banner used to count failed records too, so it
		// kept showing "📷 N…" alongside the "Retry uploads" button after
		// every upload had permanently failed. Failed items only retry on
		// the manual button, so they aren't "in flight".
		const def = loadStore();
		def.state.pendingUploads = [
			{ id: 1, status: 'failed' },
			{ id: 2, status: 'failed' },
		];
		expect(def.state.isUploading).toBe(false);
		expect(def.state.uploadBannerText).toBe('');
	});

	test('isUploading is true when at least one pending item remains', () => {
		const def = loadStore();
		def.state.pendingUploads = [
			{ id: 1, status: 'pending' },
			{ id: 2, status: 'failed' },
		];
		expect(def.state.isUploading).toBe(true);
		// Banner counts only the in-flight item, not the failed one.
		expect(def.state.uploadBannerText).toBe('\u{1f4f7} 1…');
	});
});
