/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// When a guest's `pixfete_<id>` cookie fails server-side validation
// (signature invalid, expired, or eventVersion bumped), the gallery endpoint
// returns 403. The original behavior treated this like any other failure —
// guests were stranded on a "Failed to load photos." screen with a Try Again
// button that kept hitting the same 403. The fix: clear the bad cookie, stop
// polling, and bounce the guest back to the password gate so they can re-auth.

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
			cookiePath: '/',
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
	document.cookie = 'pixfete_42=stale.cookie; path=/';
});

afterEach(() => {
	delete global.fetch;
	document.cookie = 'pixfete_42=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
});

function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

describe('loadPhotos with stale cookie (403)', () => {
	test('bounces back to password gate, clears cookie, and stops polling', async () => {
		const store = loadStore();
		const { state, actions } = store;

		state.currentView = 'gallery';
		state.photos = [{ id: 99 }];
		state.currentPage = 3;
		state.latestUploadedAt = 12345;
		state.pollingId = 7777;
		global.clearInterval = jest.fn();

		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: false,
				status: 403,
				headers: { get: () => '1' },
				json: () => Promise.resolve({}),
			})
		);

		await mockRunGenerator(actions.loadPhotos());

		expect(state.currentView).toBe('password');
		expect(state.photos).toEqual([]);
		expect(state.currentPage).toBe(1);
		expect(state.latestUploadedAt).toBe(0);
		expect(state.errorMessage).toBe(require('../__fixtures__/i18n').sessionExpired);
		expect(global.clearInterval).toHaveBeenCalledWith(7777);
		expect(state.pollingId).toBe(0);
		expect(document.cookie).not.toMatch(/pixfete_42=/);
	});

	test('non-403 failures keep the old "Failed to load photos" error and gallery view', async () => {
		const store = loadStore();
		const { state, actions } = store;

		state.currentView = 'gallery';

		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: false,
				status: 500,
				headers: { get: () => '1' },
				json: () => Promise.resolve({}),
			})
		);

		await mockRunGenerator(actions.loadPhotos());

		expect(state.currentView).toBe('gallery');
		expect(state.errorMessage).toBe(require('../__fixtures__/i18n').loadPhotosFailed);
		expect(document.cookie).toMatch(/pixfete_42=stale.cookie/);
	});
});
