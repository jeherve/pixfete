/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock @wordpress/interactivity with a store stub that captures the definition
// so we can test the handleFileSelect generator directly.
let registeredStore = {};
jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			registeredStore = definition;
			return definition;
		},
		getContext: () => ({
			pageId: 42,
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
	global.fetch = jest.fn();
});

afterEach(() => {
	delete global.fetch;
});

function loadStore() {
	require('../view');
	return registeredStore;
}

/**
 * Drive a generator function to completion by yielding each promise.
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

function makeFileList(count) {
	const files = [];
	for (let i = 0; i < count; i++) {
		files.push(new File(['data'], `photo${i}.jpg`, { type: 'image/jpeg' }));
	}
	return {
		files,
		length: files.length,
		[Symbol.iterator]() {
			return files[Symbol.iterator]();
		},
	};
}

describe('handleFileSelect upload progress tracking', () => {
	test('sets uploadTotal and uploadCurrent during upload', async () => {
		const store = loadStore();
		const { state, actions } = store;

		// Track uploadCurrent values at the moment each fetch is called.
		const currentValues = [];
		global.fetch = jest.fn(() => {
			currentValues.push(state.uploadCurrent);
			return Promise.resolve({
				ok: true,
				json: () =>
					Promise.resolve({
						id: 1,
						thumbnail: 'thumb.jpg',
						full: 'full.jpg',
						guest_name: 'Guest',
						uploaded_at: 100,
					}),
			});
		});

		const event = {
			target: { files: makeFileList(3), value: '' },
		};

		await runGenerator(actions.handleFileSelect(event));

		expect(currentValues).toEqual([1, 2, 3]);
		// After completion, state is reset.
		expect(state.uploadTotal).toBe(0);
		expect(state.uploadCurrent).toBe(0);
	});

	test('collects errors and sets summary after batch', async () => {
		const store = loadStore();
		const { state, actions } = store;

		let callCount = 0;
		global.fetch = jest.fn(() => {
			callCount++;
			if (callCount === 2) {
				// Second file fails with a server error.
				return Promise.resolve({
					ok: false,
					json: () =>
						Promise.resolve({
							message: 'File too large',
						}),
				});
			}
			return Promise.resolve({
				ok: true,
				json: () =>
					Promise.resolve({
						id: callCount,
						thumbnail: 'thumb.jpg',
						full: 'full.jpg',
						guest_name: 'Guest',
						uploaded_at: 100 + callCount,
					}),
			});
		});

		const event = {
			target: { files: makeFileList(3), value: '' },
		};

		await runGenerator(actions.handleFileSelect(event));

		// The error message should be the specific server message (single failure).
		expect(state.errorMessage).toBe('File too large');
		// Upload state should be reset.
		expect(state.uploadTotal).toBe(0);
	});

	test('shows count summary when multiple files fail', async () => {
		const store = loadStore();
		const { state, actions } = store;

		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: false,
				json: () => Promise.resolve({ message: 'Upload failed' }),
			})
		);

		const event = {
			target: { files: makeFileList(4), value: '' },
		};

		await runGenerator(actions.handleFileSelect(event));

		expect(state.errorMessage).toBe('4 of 4 photos failed to upload.');
	});

	test('catches network errors and pushes to uploadErrors', async () => {
		const store = loadStore();
		const { state, actions } = store;

		global.fetch = jest.fn(() => Promise.reject(new Error('Network error')));

		const event = {
			target: { files: makeFileList(1), value: '' },
		};

		await runGenerator(actions.handleFileSelect(event));

		expect(state.errorMessage).toBe('Upload failed. Please check your connection and try again.');
		expect(state.uploadTotal).toBe(0);
	});

	test('guards against concurrent batches', async () => {
		const store = loadStore();
		const { state, actions } = store;

		// Simulate an in-progress upload.
		state.uploadTotal = 2;

		global.fetch = jest.fn();

		const event = {
			target: { files: makeFileList(1), value: '' },
		};

		await runGenerator(actions.handleFileSelect(event));

		// fetch should not have been called — the guard returned early.
		expect(global.fetch).not.toHaveBeenCalled();
		// uploadTotal should remain unchanged (not reset to 0).
		expect(state.uploadTotal).toBe(2);
	});
});
