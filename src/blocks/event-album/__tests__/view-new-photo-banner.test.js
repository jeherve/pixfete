/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock @wordpress/interactivity with a minimal store harness so we can read
// the registered store definition from the module under test.
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
			restBase: '/wp-json/pixfete/v1',
			dateStart: '',
			dateEnd: '',
			i18n: require('../__fixtures__/i18n'),
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

describe('newPhotoBannerText', () => {
	test('uses the singular form when exactly one photo is pending', () => {
		const store = loadStore();
		store.state.newPhotoCount = 1;
		expect(store.state.newPhotoBannerText).toBe('1 new photo — tap to see');
	});

	test('uses the plural form for multiple pending photos', () => {
		const store = loadStore();
		store.state.newPhotoCount = 5;
		expect(store.state.newPhotoBannerText).toBe('5 new photos — tap to see');
	});

	test('uses the plural form when zero photos are pending', () => {
		const store = loadStore();
		store.state.newPhotoCount = 0;
		expect(store.state.newPhotoBannerText).toBe('0 new photos — tap to see');
	});
});
