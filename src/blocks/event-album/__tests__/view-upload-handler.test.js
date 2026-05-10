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
});
