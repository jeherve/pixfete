/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

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
	jest.resetModules();
	registeredStore = {};
	mockContext = {
		pageId: 42,
		restBase: '/wp-json/pixfete/v1',
		restNonce: 'test-nonce',
		dateStart: '',
		dateEnd: '',
	};
});

function loadStore() {
	require('../view');
	return registeredStore;
}

function seedPhotos(store, count) {
	store.state.photos = Array.from({ length: count }, (_, i) => ({
		id: 100 + i,
		full: `full-${i}.jpg`,
		thumbnail: `thumb-${i}.jpg`,
		guest_name: `Guest ${i}`,
	}));
}

describe('lightbox navigation actions', () => {
	test('nextPhoto advances the index', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;

		store.actions.nextPhoto();

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('nextPhoto stops at the last photo', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 2;

		store.actions.nextPhoto();

		expect(store.state.lightboxIndex).toBe(2);
	});

	test('prevPhoto decrements the index', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 2;

		store.actions.prevPhoto();

		expect(store.state.lightboxIndex).toBe(1);
	});

	test('prevPhoto stops at the first photo', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;

		store.actions.prevPhoto();

		expect(store.state.lightboxIndex).toBe(0);
	});

	test('canGoPrev and canGoNext reflect edges', () => {
		const store = loadStore();
		seedPhotos(store, 3);

		store.state.lightboxIndex = 0;
		expect(store.state.canGoPrev).toBe(false);
		expect(store.state.canGoNext).toBe(true);

		store.state.lightboxIndex = 1;
		expect(store.state.canGoPrev).toBe(true);
		expect(store.state.canGoNext).toBe(true);

		store.state.lightboxIndex = 2;
		expect(store.state.canGoPrev).toBe(true);
		expect(store.state.canGoNext).toBe(false);
	});

	test('canGoPrev and canGoNext are false when lightbox is closed', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = -1;

		expect(store.state.canGoPrev).toBe(false);
		expect(store.state.canGoNext).toBe(false);
	});

	test('nextPhoto stops event propagation when given an event', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 0;
		const stopPropagation = jest.fn();

		store.actions.nextPhoto({ stopPropagation });

		expect(stopPropagation).toHaveBeenCalledTimes(1);
		expect(store.state.lightboxIndex).toBe(1);
	});

	test('prevPhoto stops event propagation when given an event', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = 1;
		const stopPropagation = jest.fn();

		store.actions.prevPhoto({ stopPropagation });

		expect(stopPropagation).toHaveBeenCalledTimes(1);
		expect(store.state.lightboxIndex).toBe(0);
	});

	test('nextPhoto does nothing when the lightbox is closed', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = -1;

		store.actions.nextPhoto();

		expect(store.state.lightboxIndex).toBe(-1);
	});

	test('prevPhoto does nothing when the lightbox is closed', () => {
		const store = loadStore();
		seedPhotos(store, 3);
		store.state.lightboxIndex = -1;

		store.actions.prevPhoto();

		expect(store.state.lightboxIndex).toBe(-1);
	});
});
