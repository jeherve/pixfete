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

describe('Service Worker drainQueue', () => {
	test('uploads pending items and notifies clients', async () => {
		const id = await enqueue({
			pageId: 7,
			blob: new Blob(['x'], { type: 'image/jpeg' }),
			name: 'a.jpg',
		});
		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => ({ id: 99, full: 'u', thumbnail: 't', guest_name: 'g', uploaded_at: 1 }),
		});

		await drainQueue('https://example.test/wp-json/pixfete/v1');

		expect(global.fetch).toHaveBeenCalledTimes(1);
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
		});
		global.fetch.mockResolvedValueOnce({ ok: false });

		await drainQueue('https://example.test/wp-json/pixfete/v1');

		const remaining = await listPending(7);
		expect(remaining[0].status).toBe('failed');
		expect(postedMessages[0]).toMatchObject({
			type: 'pixfete:upload-failed',
			queueId: id,
		});
	});
});
