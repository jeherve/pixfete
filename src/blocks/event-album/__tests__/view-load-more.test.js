/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock @wordpress/interactivity with a store stub that wraps generator actions
// into Promise-returning functions, matching how the real Interactivity API
// runtime works. This is critical for testing loadMore, which calls
// actions.loadPhotos() internally — in production that returns a Promise,
// not a raw generator.
let mockRegisteredStore = {};

/**
 * Drive a generator to completion, awaiting each yielded value.
 *
 * @param {Object} gen The generator to run.
 * @return {Promise}      Resolves when the generator finishes.
 */
const mockRunGenerator = async (gen) => {
	let result = gen.next();
	while (!result.done) {
		try {
			const value = await result.value;
			result = gen.next(value);
		} catch (error) {
			result = gen.throw(error);
		}
	}
	return result.value;
};

jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			if (definition) {
				mockRegisteredStore = definition;
			}
			// On subsequent calls (from inside actions like loadMore),
			// return a view with wrapped actions so that
			// actions.loadPhotos() returns a Promise (not a raw generator).
			const wrapped = {};
			if (mockRegisteredStore.actions) {
				for (const [key, fn] of Object.entries(mockRegisteredStore.actions)) {
					if (fn.constructor && fn.constructor.name === 'GeneratorFunction') {
						wrapped[key] = (...args) => mockRunGenerator(fn(...args));
					} else {
						wrapped[key] = fn;
					}
				}
			}
			return { ...mockRegisteredStore, actions: wrapped };
		},
		getContext: () => ({
			pageId: 42,
			restBase: 'http://localhost/wp-json/pixfete/v1',
			dateStart: '',
			dateEnd: '',
			i18n: require('../__fixtures__/i18n'),
		}),
	}),
	{ virtual: true }
);

beforeEach(() => {
	mockRegisteredStore = {};
	jest.resetModules();
	global.fetch = jest.fn();
});

afterEach(() => {
	delete global.fetch;
});

function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

describe('loadMore', () => {
	test('increments currentPage and delegates to loadPhotos without throwing', async () => {
		const store = loadStore();
		const { state, actions } = store;

		// Seed the gallery with an initial page of photos.
		state.photos = [
			{
				id: 1,
				thumbnail: 't.jpg',
				src: 's.jpg',
				srcset: 's-300.jpg 300w, s-768.jpg 768w',
				sizes: '(min-width: 601px) 33vw, 100vw',
				full: 'f.jpg',
				guest_name: 'A',
				uploaded_at: 10,
			},
		];
		state.currentPage = 1;
		state.hasMore = true;

		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: true,
				headers: { get: () => '3' },
				json: () =>
					Promise.resolve([
						{
							id: 2,
							thumbnail: 't2.jpg',
							src: 's2.jpg',
							srcset: 's2-300.jpg 300w, s2-768.jpg 768w',
							sizes: '(min-width: 601px) 33vw, 100vw',
							full: 'f2.jpg',
							guest_name: 'B',
							uploaded_at: 20,
						},
					]),
			})
		);

		// loadMore must not throw — the original bug was that yield* on
		// a wrapped generator action caused "not iterable" TypeError.
		await mockRunGenerator(actions.loadMore());

		expect(state.currentPage).toBe(2);
		expect(state.photos).toHaveLength(2);
		expect(global.fetch).toHaveBeenCalledTimes(1);
	});
});
