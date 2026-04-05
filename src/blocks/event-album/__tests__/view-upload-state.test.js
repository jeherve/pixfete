/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock the @wordpress/interactivity module. The real module is only available
// inside WordPress at runtime, so we provide a minimal stub that captures
// the store definition for inspection.
let registeredStore = {};
jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			registeredStore = definition;
			return definition;
		},
		getContext: () => ({
			pageId: 1,
			restBase: '/wp-json/event-guest-photos-sharing/v1',
			dateStart: '',
			dateEnd: '',
		}),
	}),
	{ virtual: true }
);

beforeEach(() => {
	registeredStore = {};
	jest.resetModules();
});

function loadStore() {
	require('../view');
	return registeredStore;
}

describe('Upload progress state', () => {
	test('initial upload state properties are set to zero/empty', () => {
		const store = loadStore();

		expect(store.state.uploadTotal).toBe(0);
		expect(store.state.uploadCurrent).toBe(0);
		expect(store.state.uploadErrors).toEqual([]);
	});

	test('isUploading returns false when uploadTotal is 0', () => {
		const store = loadStore();

		expect(store.state.isUploading).toBe(false);
	});

	test('isUploading returns true when uploadTotal is greater than 0', () => {
		const store = loadStore();
		store.state.uploadTotal = 3;

		expect(store.state.isUploading).toBe(true);
	});

	test('uploadBannerText returns correct format', () => {
		const store = loadStore();
		store.state.uploadTotal = 5;
		store.state.uploadCurrent = 2;

		expect(store.state.uploadBannerText).toBe('\u{1f4f7} 2 / 5\u2026');
	});
});
