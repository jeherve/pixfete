/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';

const i18nFixture = require('../__fixtures__/i18n');

let mockRegisteredStore = {};
let mockContext = {};
let listPending;
let enqueue;
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

beforeEach(async () => {
	// Reset modules first so we get a fresh upload-queue cache, matching the
	// instance view.js will require. Replacing the global indexedDB with a
	// fresh IDBFactory orphans any open connections from prior modules and
	// gives this test a clean slate without needing those modules to
	// participate in cleanup.
	jest.resetModules();
	globalThis.indexedDB = new IDBFactory();
	const queue = require('../upload-queue');
	listPending = queue.listPending;
	enqueue = queue.enqueue;

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

async function runGenerator(gen) {
	let result = gen.next();
	while (!result.done) {
		const value = await result.value;
		result = gen.next(value);
	}
	return result.value;
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

describe('handleFileSelect enqueues to IndexedDB', () => {
	test('enqueues every selected file', async () => {
		const def = loadStore();
		// Stub drainQueue so this test only asserts the enqueue half.
		def.actions.drainQueue = jest.fn().mockResolvedValue();

		const files = makeFileList(3);
		const event = { target: { files, value: '' } };
		await runGenerator(def.actions.handleFileSelect(event));

		const items = await listPending(42);
		expect(items).toHaveLength(3);
		expect(items.map((i) => i.name)).toEqual(['photo0.jpg', 'photo1.jpg', 'photo2.jpg']);
		expect(def.state.pendingUploads).toHaveLength(3);
		expect(def.actions.drainQueue).toHaveBeenCalledTimes(1);
	});

	test('does nothing for an empty selection', async () => {
		const def = loadStore();
		def.actions.drainQueue = jest.fn().mockResolvedValue();

		const event = { target: { files: [], value: '' } };
		await runGenerator(def.actions.handleFileSelect(event));

		expect(def.actions.drainQueue).not.toHaveBeenCalled();
		expect(def.state.pendingUploads).toEqual([]);
	});

	test('resets the input value so the same file can be reselected', async () => {
		const def = loadStore();
		def.actions.drainQueue = jest.fn().mockResolvedValue();

		const files = makeFileList(1);
		const event = { target: { files, value: 'foo' } };
		await runGenerator(def.actions.handleFileSelect(event));

		expect(event.target.value).toBe('');
	});
});

describe('drainQueue uploads pending items', () => {
	test('successful upload removes item from queue and prepends photo', async () => {
		const def = loadStore();
		def.state.pendingUploads = [];
		// Pre-populate queue with one item.
		const id = await enqueue({
			pageId: 42,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
		});
		def.state.pendingUploads = await listPending(42);

		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => ({ id: 99, full: 'u', thumbnail: 't', guest_name: 'g', uploaded_at: 1 }),
		});

		await def.actions.drainQueue();

		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(def.state.photos[0].id).toBe(99);
		const remaining = await listPending(42);
		expect(remaining).toHaveLength(0);
		expect(def.state.pendingUploads).toEqual([]);
		// Make sure we didn't accidentally leave the id captured.
		expect(id).toBeGreaterThan(0);
	});

	test('failed upload marks item failed and stops draining', async () => {
		const def = loadStore();
		await enqueue({
			pageId: 42,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
		});
		def.state.pendingUploads = await listPending(42);

		global.fetch.mockResolvedValueOnce({
			ok: false,
			json: async () => ({ message: 'nope' }),
		});

		await def.actions.drainQueue();

		const remaining = await listPending(42);
		expect(remaining).toHaveLength(1);
		expect(remaining[0].status).toBe('failed');
		expect(remaining[0].attempts).toBe(1);
		expect(def.state.pendingUploads[0].status).toBe('failed');
	});

	test('skips records already in failed state — manual retry is the user gate', async () => {
		// Regression: the in-page drain used to re-attempt failed records on
		// every entry point (init resume, consent accept, new file select),
		// contradicting the docblock that says permanent failures surface a
		// deliberate user action. The SW already skipped them; this test
		// locks in the same behavior for the in-page path so retry semantics
		// are identical on Chrome (SW-driven) and Safari/Firefox (page-driven).
		const def = loadStore();
		const id = await enqueue({
			pageId: 42,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'failed.jpg',
		});
		const { markFailed } = require('../upload-queue');
		await markFailed(id, 'http');

		await def.actions.drainQueue();

		expect(global.fetch).not.toHaveBeenCalled();
		const remaining = await listPending(42);
		expect(remaining[0].status).toBe('failed');
	});

	test('a 200 with non-JSON body succeeds without duplicate upload', async () => {
		// Regression: caching plugins (WP Super Cache, Cache Enabler) and
		// CDNs sometimes intercept REST POST responses with HTML. The
		// server already accepted the upload — marking the record failed
		// here would auto-retry and produce duplicates.
		const def = loadStore();
		await enqueue({
			pageId: 42,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
		});
		def.state.pendingUploads = await listPending(42);

		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => {
				throw new SyntaxError('Unexpected token < in JSON');
			},
		});
		const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

		await def.actions.drainQueue();

		const remaining = await listPending(42);
		expect(remaining).toHaveLength(0);
		warnSpy.mockRestore();
	});
});

describe('drainQueue treats a network failure as unfinished, not failed', () => {
	test('a fetch rejection leaves the record pending so Background Sync can recover it', async () => {
		// Regression: a network-layer throw used to mark the record 'failed'.
		// Background Sync's drain skips 'failed' records, so the very upload
		// Background Sync exists to recover could never complete — and the
		// guest was pushed behind the manual "Retry uploads" gate for what was
		// only a transient blip. A throw means "never reached the server", so
		// the record must stay 'pending' for an automatic retry.
		const def = loadStore();
		await enqueue({
			pageId: 42,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
		});
		def.state.pendingUploads = await listPending(42);

		global.fetch.mockRejectedValueOnce(new Error('network down'));

		await def.actions.drainQueue();

		const remaining = await listPending(42);
		expect(remaining).toHaveLength(1);
		expect(remaining[0].status).toBe('pending');
		expect(def.state.pendingUploads[0].status).toBe('pending');
	});
});

describe('uploads happen in the foreground, with Background Sync as recovery only', () => {
	function mockServiceWorker() {
		const syncRegister = jest.fn().mockResolvedValue();
		Object.defineProperty(navigator, 'serviceWorker', {
			configurable: true,
			value: {
				getRegistration: jest.fn().mockResolvedValue({ active: { scriptURL: '/pixfete-sw' } }),
				ready: Promise.resolve({ sync: { register: syncRegister } }),
				register: jest.fn().mockResolvedValue({}),
				addEventListener: jest.fn(),
			},
		});
		return syncRegister;
	}

	afterEach(() => {
		delete navigator.serviceWorker;
	});

	test('an online upload appears immediately without waiting for Background Sync', async () => {
		// Root-cause regression: when Background Sync was available the code
		// skipped the in-page drain and deferred the POST to the browser's
		// `sync` event, so the uploader's own photo only appeared (via the SW
		// broadcast) minutes later — or after they had closed the tab. The
		// in-page drain must run regardless so the photo shows right away.
		const syncRegister = mockServiceWorker();
		const def = loadStore();

		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => ({ id: 77, full: 'u', thumbnail: 't', guest_name: 'g', uploaded_at: 9 }),
		});

		const files = makeFileList(1);
		await runGenerator(def.actions.handleFileSelect({ target: { files, value: '' } }));

		// The photo is in the gallery now — the foreground drain POSTed it.
		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(def.state.photos[0].id).toBe(77);
		expect(await listPending(42)).toHaveLength(0);
		// Nothing was left unfinished, so we never fall back to Background Sync.
		expect(syncRegister).not.toHaveBeenCalled();
	});

	test('a down-network upload is marked failed when Background Sync is unavailable', async () => {
		// Browsers without the Background Sync API (iOS Safari, Firefox) can't
		// recover a record left 'pending' by a network blip: the SW never
		// retries it and the "Retry uploads" button only surfaces for 'failed'
		// records. Without this the placeholder is stuck on "Uploading…"
		// forever. There is no navigator.serviceWorker here, so
		// tryRegisterBackgroundSync returns false and the leftover must be
		// marked failed to restore a user-visible recovery path.
		const def = loadStore();

		global.fetch.mockRejectedValueOnce(new Error('network down'));

		const files = makeFileList(1);
		await runGenerator(def.actions.handleFileSelect({ target: { files, value: '' } }));

		const remaining = await listPending(42);
		expect(remaining).toHaveLength(1);
		expect(remaining[0].status).toBe('failed');
		expect(def.state.pendingUploads[0].status).toBe('failed');
	});

	test('an upload that fails on a down network is handed to Background Sync', async () => {
		// The recovery half: when the foreground drain can't reach the server,
		// the record stays pending and Background Sync is registered to finish
		// it once connectivity returns — even if the tab closes first.
		const syncRegister = mockServiceWorker();
		const def = loadStore();

		global.fetch.mockRejectedValueOnce(new Error('network down'));

		const files = makeFileList(1);
		await runGenerator(def.actions.handleFileSelect({ target: { files, value: '' } }));

		expect(def.state.photos).toHaveLength(0);
		const remaining = await listPending(42);
		expect(remaining[0].status).toBe('pending');
		expect(syncRegister).toHaveBeenCalledWith('pixfete-upload-queue');
	});
});

describe('handleFileSelect persists restBase for SW recovery', () => {
	test('records each queued upload with the page-computed restBase', async () => {
		// Regression: the SW used to derive restBase from its scope, which
		// dropped subdirectory prefixes and silently misrouted uploads on
		// subdir multisite. Each queue record now carries restBase so the
		// SW can route to the right WordPress site even across cold starts.
		const def = loadStore();
		def.actions.drainQueue = jest.fn().mockResolvedValue();
		mockContext.restBase = 'https://example.test/blog/wp-json/pixfete/v1';

		const files = makeFileList(2);
		const event = { target: { files, value: '' } };
		await runGenerator(def.actions.handleFileSelect(event));

		const items = await listPending(42);
		expect(items).toHaveLength(2);
		for (const item of items) {
			expect(item.restBase).toBe('https://example.test/blog/wp-json/pixfete/v1');
		}
	});
});
