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
});
