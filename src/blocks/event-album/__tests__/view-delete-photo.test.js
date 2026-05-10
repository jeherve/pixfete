/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

const i18nFixture = require('../__fixtures__/i18n');

// Mock @wordpress/interactivity with a store stub that captures the definition
// so we can test the deletePhoto generator directly.
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
		restNonce: 'test-nonce-abc',
		dateStart: '',
		dateEnd: '',
		i18n: { ...i18nFixture },
		item: {
			id: 101,
			guest_name: 'Alice',
			thumbnail: 'thumb.jpg',
			full: 'full.jpg',
		},
	};
	jest.resetModules();
	global.fetch = jest.fn();
	window.confirm = jest.fn();
});

afterEach(() => {
	delete global.fetch;
	delete window.confirm;
});

function loadStore() {
	require('../view');
	return registeredStore;
}

/**
 * Drive a generator function to completion by yielding each promise.
 *
 * The Interactivity API uses generator functions for async actions.
 * This helper steps through each yielded value (typically a fetch Promise),
 * resolving it and feeding the result back into the generator.
 *
 * @param {Object} gen The generator to run.
 * @return {Promise}      Resolves when the generator is done.
 */
async function runGenerator(gen) {
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
}

describe('deletePhoto action', () => {
	test('shows confirmation dialog with guest name', async () => {
		const store = loadStore();
		const { actions } = store;

		window.confirm = jest.fn(() => false);

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(window.confirm).toHaveBeenCalledWith('Alice — delete this photo? This cannot be undone.');
	});

	test('sends DELETE fetch with correct URL and nonce on confirmation', async () => {
		const store = loadStore();
		const { actions } = store;

		window.confirm = jest.fn(() => true);
		global.fetch = jest.fn(() => Promise.resolve({ ok: true }));

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(global.fetch).toHaveBeenCalledWith(
			'/wp-json/pixfete/v1/photos/42/101',
			expect.objectContaining({
				method: 'DELETE',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': 'test-nonce-abc' },
			})
		);
	});

	test('removes photo from state on successful 204 response', async () => {
		const store = loadStore();
		const { state, actions } = store;

		// Populate state with some photos including the one to delete.
		state.photos = [
			{ id: 100, guest_name: 'Bob' },
			{ id: 101, guest_name: 'Alice' },
			{ id: 102, guest_name: 'Carol' },
		];

		window.confirm = jest.fn(() => true);
		global.fetch = jest.fn(() => Promise.resolve({ ok: true }));

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(state.photos).toEqual([
			{ id: 100, guest_name: 'Bob' },
			{ id: 102, guest_name: 'Carol' },
		]);
	});

	test('does not call fetch when user cancels confirmation', async () => {
		const store = loadStore();
		const { actions } = store;

		window.confirm = jest.fn(() => false);
		global.fetch = jest.fn();

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(global.fetch).not.toHaveBeenCalled();
	});

	test('sets error message on failed response', async () => {
		const store = loadStore();
		const { state, actions } = store;

		window.confirm = jest.fn(() => true);
		global.fetch = jest.fn(() => Promise.resolve({ ok: false }));

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(state.errorMessage).toBe('Failed to delete photo. Please try again.');
	});

	test('calls event.stopPropagation to prevent lightbox', async () => {
		const store = loadStore();
		const { actions } = store;

		window.confirm = jest.fn(() => false);

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(event.stopPropagation).toHaveBeenCalledTimes(1);
	});

	test('sets error message on network error', async () => {
		const store = loadStore();
		const { state, actions } = store;

		window.confirm = jest.fn(() => true);
		global.fetch = jest.fn(() => Promise.reject(new Error('Network failure')));

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(state.errorMessage).toBe('Network error. Please try again.');
	});

	test('resets deletingPhotoId after successful delete', async () => {
		const store = loadStore();
		const { state, actions } = store;

		state.photos = [{ id: 101, guest_name: 'Alice' }];

		window.confirm = jest.fn(() => true);
		global.fetch = jest.fn(() => Promise.resolve({ ok: true }));

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(state.deletingPhotoId).toBeNull();
	});

	test('resets deletingPhotoId after failed delete', async () => {
		const store = loadStore();
		const { state, actions } = store;

		window.confirm = jest.fn(() => true);
		global.fetch = jest.fn(() => Promise.resolve({ ok: false }));

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(state.deletingPhotoId).toBeNull();
	});

	test('returns early when context has no item', async () => {
		mockContext.item = null;

		const store = loadStore();
		const { actions } = store;

		window.confirm = jest.fn();
		global.fetch = jest.fn();

		const event = { stopPropagation: jest.fn() };
		await runGenerator(actions.deletePhoto(event));

		expect(window.confirm).not.toHaveBeenCalled();
		expect(global.fetch).not.toHaveBeenCalled();
	});
});
