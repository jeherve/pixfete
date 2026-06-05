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

describe('Service Worker fetch handler', () => {
	test('registers a non-trivial fetch listener that only intercepts navigations', () => {
		// Chrome will not fire `beforeinstallprompt` unless the Service
		// Worker has a fetch event handler AND that handler is non-trivial
		// — a no-op handler is detected and skipped by Chrome's
		// installability check. The handler must therefore exist and must
		// call `event.respondWith` for at least some requests so static
		// analysis sees a real interceptor. We choose navigations: routing
		// every request through the SW would add overhead and we don't
		// actually want to cache anything (see sw.js docblock).
		//
		// sw.js is `require`-cached and only evaluated against the first
		// beforeEach's `global.self`, so we must inspect the handler list
		// inside a single test rather than splitting across describes.
		const fetchCall = global.self.addEventListener.mock.calls.find(([type]) => type === 'fetch');
		expect(fetchCall).toBeDefined();
		const handler = fetchCall[1];

		const navResponse = jest.fn();
		handler({
			request: { mode: 'navigate', url: 'https://example.test/' },
			respondWith: navResponse,
		});
		expect(navResponse).toHaveBeenCalledTimes(1);

		const apiResponse = jest.fn();
		handler({
			request: { mode: 'cors', url: 'https://example.test/wp-json/pixfete/v1/photos/7' },
			respondWith: apiResponse,
		});
		expect(apiResponse).not.toHaveBeenCalled();
	});
});

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

	test('a network throw rejects the sync drain and leaves the record pending', async () => {
		// Background Sync exists to retry uploads when connectivity returns. If
		// the network drops again mid-drain, marking the record 'failed' would
		// make the drain skip it forever (the loop ignores failed records) —
		// defeating the recovery the SW is there to provide. The record must
		// stay 'pending', and the drain promise must reject so event.waitUntil()
		// tells the browser this sync attempt still needs retrying.
		await enqueue({
			pageId: 7,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
			restBase: REST_BASE,
		});
		global.fetch.mockRejectedValueOnce(new Error('network down'));

		await expect(drainQueue()).rejects.toThrow('network down');

		const remaining = await listPending(7);
		expect(remaining).toHaveLength(1);
		expect(remaining[0].status).toBe('pending');
		// No failure broadcast — the page keeps showing it as queued, not failed.
		expect(postedMessages).toEqual([]);
	});

	test('a network failure on one page still drains other reachable pages', async () => {
		// Regression: rejecting the whole drain on the first network throw
		// abandoned every other page's queue. A device can hold uploads for
		// several events (distinct restBases on multisite); one unreachable
		// origin must not strand a reachable one. The reachable page uploads,
		// the unreachable page stays 'pending', and the drain still rejects so
		// Background Sync reschedules the leftover.
		await enqueue({
			pageId: 7,
			blob: new Blob(['a'], { type: 'image/jpeg' }),
			name: 'a.jpg',
			restBase: 'https://down.test/wp-json/pixfete/v1',
		});
		await enqueue({
			pageId: 8,
			blob: new Blob(['b'], { type: 'image/jpeg' }),
			name: 'b.jpg',
			restBase: 'https://up.test/wp-json/pixfete/v1',
		});
		// Page 7's origin is unreachable; page 8's succeeds. Route by URL so the
		// result doesn't depend on the order collectPendingPageIds returns.
		global.fetch.mockImplementation((url) => {
			if (url.startsWith('https://down.test')) {
				return Promise.reject(new Error('network down'));
			}
			return Promise.resolve({ ok: true, json: async () => ({ id: 1 }) });
		});

		await expect(drainQueue()).rejects.toThrow('network down');

		// Page 8 was reachable and drained despite page 7 failing.
		expect(await listPending(8)).toHaveLength(0);
		// Page 7 stays pending (not failed) for Background Sync to retry.
		const stranded = await listPending(7);
		expect(stranded).toHaveLength(1);
		expect(stranded[0].status).toBe('pending');
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
