/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

import 'fake-indexeddb/auto';

const { enqueue, listPending, resetForTests } = require('../blocks/event-album/upload-queue');

let drainQueue;
let postedMessages = [];

beforeEach(async () => {
	await resetForTests();
	postedMessages = [];

	// Stub the SW global surface used by sw.js. The SW source registers
	// `self.addEventListener` calls at module load time, so the stub must
	// be in place before the (cached) `require('../sw')` is first evaluated.
	global.self = {
		addEventListener: jest.fn(),
		skipWaiting: jest.fn(),
		clients: {
			claim: jest.fn().mockResolvedValue(),
			matchAll: jest.fn().mockResolvedValue([{ postMessage: (m) => postedMessages.push(m) }]),
		},
	};
	global.fetch = jest.fn();

	// Require once and reuse: the SW shares the test file's upload-queue
	// module instance, so `resetForTests` above clears its DB state too.
	// Re-requiring after `jest.resetModules()` would give the SW its own
	// queue module with a separate cached IDB connection, deadlocking the
	// fake-indexeddb deletion in subsequent tests.
	({ drainQueue } = require('../sw'));
});

afterEach(() => {
	delete global.self;
	delete global.fetch;
});

const REST_BASE = 'https://example.test/wp-json/pixfete/v1';

describe('Service Worker drainQueue', () => {
	test('uploads pending items and notifies clients', async () => {
		const id = await enqueue({
			pageId: 7,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
			restBase: REST_BASE,
		});
		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => ({ id: 99, full: 'u', thumbnail: 't', guest_name: 'g', uploaded_at: 1 }),
		});

		await drainQueue();

		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(global.fetch).toHaveBeenCalledWith(
			`${REST_BASE}/photos/7`,
			expect.objectContaining({ method: 'POST', credentials: 'same-origin' })
		);
		const remaining = await listPending(7);
		expect(remaining).toHaveLength(0);
		expect(postedMessages).toEqual([
			expect.objectContaining({
				type: 'pixfete:upload-success',
				queueId: id,
				pageId: 7,
				photo: expect.objectContaining({ id: 99 }),
			}),
		]);
	});

	test('marks failure and notifies clients on non-OK response', async () => {
		const id = await enqueue({
			pageId: 7,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
			restBase: REST_BASE,
		});
		global.fetch.mockResolvedValueOnce({ ok: false });

		await drainQueue();

		const remaining = await listPending(7);
		expect(remaining[0].status).toBe('failed');
		expect(postedMessages[0]).toMatchObject({
			type: 'pixfete:upload-failed',
			queueId: id,
		});
	});

	test('uses the restBase stashed on each queue record per pageId', async () => {
		// Two events with different REST bases — e.g. subdirectory multisite
		// where each subsite has its own `/site-X/wp-json/...`. The SW must
		// route each event's upload to the right origin.
		await enqueue({
			pageId: 7,
			blob: new Blob(['a'], { type: 'image/jpeg' }),
			name: 'a.jpg',
			restBase: 'https://example.test/site-a/wp-json/pixfete/v1',
		});
		await enqueue({
			pageId: 8,
			blob: new Blob(['b'], { type: 'image/jpeg' }),
			name: 'b.jpg',
			restBase: 'https://example.test/site-b/wp-json/pixfete/v1',
		});
		global.fetch.mockResolvedValue({
			ok: true,
			json: async () => ({ id: 1 }),
		});

		await drainQueue();

		const urls = global.fetch.mock.calls.map((c) => c[0]);
		expect(urls).toContain('https://example.test/site-a/wp-json/pixfete/v1/photos/7');
		expect(urls).toContain('https://example.test/site-b/wp-json/pixfete/v1/photos/8');
	});

	test('treats a 200 with non-JSON body as success (avoids duplicate uploads)', async () => {
		// Real-world reproduction: a caching plugin (WP Super Cache, Cache
		// Enabler) or CDN intercepts the POST response and returns HTML. The
		// server accepted the upload; retrying would produce duplicates.
		const id = await enqueue({
			pageId: 7,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
			restBase: REST_BASE,
		});
		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => {
				throw new SyntaxError('Unexpected token < in JSON');
			},
		});
		const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

		await drainQueue();

		const remaining = await listPending(7);
		expect(remaining).toHaveLength(0);
		expect(postedMessages[0]).toMatchObject({
			type: 'pixfete:upload-done-opaque',
			queueId: id,
			pageId: 7,
		});
		warnSpy.mockRestore();
	});

	test('records missing restBase as failed instead of guessing an origin', async () => {
		// Upgrade case: a queue record persisted before the restBase field
		// existed. We refuse to route the upload to a fabricated URL because
		// on multisite the wrong origin would land it on a sibling site.
		const id = await enqueue({
			pageId: 7,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
		});

		await drainQueue();

		expect(global.fetch).not.toHaveBeenCalled();
		const remaining = await listPending(7);
		expect(remaining[0].status).toBe('failed');
		expect(remaining[0].lastError).toBe('no-rest-base');
		expect(postedMessages[0]).toMatchObject({ type: 'pixfete:upload-failed', queueId: id });
	});
});
