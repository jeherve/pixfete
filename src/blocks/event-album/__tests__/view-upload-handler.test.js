/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

const i18nFixture = require('../__fixtures__/i18n');

let mockRegisteredStore = {};
let mockContext = {};
jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			if (definition) {
				mockRegisteredStore = definition;
			}
			return mockRegisteredStore;
		},
		getContext: () => mockContext,
	}),
	{ virtual: true }
);

beforeEach(() => {
	jest.resetModules();
	mockRegisteredStore = {};
	mockContext = {
		pageId: 42,
		restBase: '/wp-json/pixfete/v1',
		dateStart: '',
		dateEnd: '',
		i18n: { ...i18nFixture, queuedLabel: 'Queued', failedLabel: 'Upload failed' },
	};
	global.fetch = jest.fn();
});

afterEach(() => {
	delete global.fetch;
});

function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

function makeFileList(count) {
	const files = [];
	for (let i = 0; i < count; i++) {
		files.push(new File(['data'], `photo${i}.jpg`, { type: 'image/jpeg' }));
	}
	const arrayIterator = Array.prototype[Symbol.iterator];
	return Object.assign(files, {
		[Symbol.iterator]() {
			return arrayIterator.call(files);
		},
	});
}

function okPhoto(photo) {
	return { ok: true, json: async () => photo };
}

describe('handleFileSelect queues placeholders and uploads them', () => {
	test('does nothing for an empty selection', async () => {
		const def = loadStore();

		await def.actions.handleFileSelect({ target: { files: [], value: '' } });

		expect(global.fetch).not.toHaveBeenCalled();
		expect(def.state.pendingUploads).toEqual([]);
	});

	test('resets the input value so the same file can be reselected', async () => {
		const def = loadStore();
		global.fetch.mockResolvedValue(okPhoto({ id: 1, uploaded_at: 1 }));

		const event = { target: { files: makeFileList(1), value: 'foo' } };
		await def.actions.handleFileSelect(event);

		expect(event.target.value).toBe('');
	});

	test('a successful upload appears immediately and clears its placeholder', async () => {
		// The whole point of dropping the IndexedDB queue + Background Sync:
		// an online guest's photo is POSTed in the foreground and prepended to
		// the gallery right away, with no placeholder left behind.
		const def = loadStore();
		global.fetch.mockResolvedValueOnce(
			okPhoto({ id: 99, full: 'u', thumbnail: 't', guest_name: 'g', uploaded_at: 5 })
		);

		await def.actions.handleFileSelect({ target: { files: makeFileList(1), value: '' } });

		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(def.state.photos[0].id).toBe(99);
		expect(def.state.latestUploadedAt).toBe(5);
		expect(def.state.pendingUploads).toEqual([]);
	});

	test('uploads every selected file in one batch', async () => {
		const def = loadStore();
		global.fetch
			.mockResolvedValueOnce(okPhoto({ id: 1, uploaded_at: 1 }))
			.mockResolvedValueOnce(okPhoto({ id: 2, uploaded_at: 2 }))
			.mockResolvedValueOnce(okPhoto({ id: 3, uploaded_at: 3 }));

		await def.actions.handleFileSelect({ target: { files: makeFileList(3), value: '' } });

		expect(global.fetch).toHaveBeenCalledTimes(3);
		expect(def.state.pendingUploads).toEqual([]);
		expect(def.state.photos.map((p) => p.id).sort()).toEqual([1, 2, 3]);
	});
});

describe('uploadPending surfaces failures for manual retry', () => {
	test('a non-2xx response marks that placeholder failed', async () => {
		const def = loadStore();
		global.fetch.mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'nope' }) });

		await def.actions.handleFileSelect({ target: { files: makeFileList(1), value: '' } });

		expect(def.state.pendingUploads).toHaveLength(1);
		expect(def.state.pendingUploads[0].status).toBe('failed');
		expect(def.state.pendingUploads[0].isFailed).toBe(true);
		expect(def.state.photos).toHaveLength(0);
	});

	test('a network rejection marks that placeholder failed', async () => {
		const def = loadStore();
		global.fetch.mockRejectedValueOnce(new Error('network down'));

		await def.actions.handleFileSelect({ target: { files: makeFileList(1), value: '' } });

		expect(def.state.pendingUploads).toHaveLength(1);
		expect(def.state.pendingUploads[0].status).toBe('failed');
	});

	test('one bad file does not block the rest of the batch', async () => {
		// Regression intent: the old drain stopped on the first failure. With
		// no Background Sync to pick up the leftovers, a single failure must not
		// strand the other files — each is attempted and only the failed one is
		// left behind for retry.
		const def = loadStore();
		global.fetch
			.mockResolvedValueOnce({ ok: false, json: async () => ({}) })
			.mockResolvedValueOnce(okPhoto({ id: 2, uploaded_at: 2 }));

		await def.actions.handleFileSelect({ target: { files: makeFileList(2), value: '' } });

		expect(global.fetch).toHaveBeenCalledTimes(2);
		expect(def.state.photos.map((p) => p.id)).toEqual([2]);
		expect(def.state.pendingUploads).toHaveLength(1);
		expect(def.state.pendingUploads[0].status).toBe('failed');
	});

	test('a 200 with a non-JSON body succeeds without a duplicate upload', async () => {
		// Caching plugins / CDNs sometimes return HTML for a successful POST.
		// The server stored the photo, so we must clear the placeholder rather
		// than retry (which would duplicate it); polling surfaces the real photo.
		const def = loadStore();
		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => {
				throw new SyntaxError('Unexpected token < in JSON');
			},
		});
		const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

		await def.actions.handleFileSelect({ target: { files: makeFileList(1), value: '' } });

		expect(def.state.pendingUploads).toEqual([]);
		expect(def.state.photos).toHaveLength(0);
		warnSpy.mockRestore();
	});
});

describe('retryUploads re-attempts only failed placeholders', () => {
	test('a previously failed upload succeeds on retry', async () => {
		const def = loadStore();

		// First attempt fails.
		global.fetch.mockResolvedValueOnce({ ok: false, json: async () => ({}) });
		await def.actions.handleFileSelect({ target: { files: makeFileList(1), value: '' } });
		expect(def.state.pendingUploads[0].status).toBe('failed');

		// Retry succeeds, re-using the File still held for the failed placeholder.
		global.fetch.mockResolvedValueOnce(okPhoto({ id: 7, uploaded_at: 7 }));
		await def.actions.retryUploads();

		expect(global.fetch).toHaveBeenCalledTimes(2);
		expect(def.state.pendingUploads).toEqual([]);
		expect(def.state.photos[0].id).toBe(7);
	});
});

describe('uploadPending is single-flight', () => {
	test('a concurrent call does not re-POST a file already being uploaded', async () => {
		// Regression: dropping the IndexedDB queue removed the per-record
		// claim that stopped a file being POSTed twice. uploadPending picks
		// work by scanning shared state for the next 'pending' item, so a
		// second call kicked off while the first is still awaiting fetch (a
		// guest selecting another batch, or tapping "Retry uploads" mid-upload)
		// would find the same item and upload it again — a duplicate photo.
		const def = loadStore();

		// Hold the first upload open at the fetch await so a second drain can
		// observe the still-'pending' item before it resolves.
		let resolveFetch;
		global.fetch.mockReturnValueOnce(
			new Promise((resolve) => {
				resolveFetch = resolve;
			})
		);

		const first = def.actions.handleFileSelect({ target: { files: makeFileList(1), value: '' } });

		// A second drain while the first is in flight must bail, not re-POST.
		await def.actions.uploadPending();
		expect(global.fetch).toHaveBeenCalledTimes(1);

		resolveFetch(okPhoto({ id: 5, uploaded_at: 5 }));
		await first;

		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(def.state.pendingUploads).toEqual([]);
		expect(def.state.photos[0].id).toBe(5);
	});
});
