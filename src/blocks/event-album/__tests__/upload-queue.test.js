/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

import 'fake-indexeddb/auto';

import { openQueue, enqueue, listPending, markDone, markFailed, resetForTests } from '../upload-queue';

beforeEach(async () => {
	await resetForTests();
});

function blob(content = 'data') {
	return new Blob([content], { type: 'image/jpeg' });
}

describe('upload-queue', () => {
	test('opens the database lazily', async () => {
		const db = await openQueue();
		expect(db.name).toBe('pixfete-uploads');
		expect(db.objectStoreNames.contains('queue')).toBe(true);
	});

	test('enqueue persists a record and returns its id', async () => {
		const id = await enqueue({ pageId: 42, blob: blob(), name: 'p.jpg' });
		expect(typeof id).toBe('number');

		const items = await listPending(42);
		expect(items).toHaveLength(1);
		expect(items[0]).toMatchObject({
			id,
			pageId: 42,
			name: 'p.jpg',
			status: 'pending',
			attempts: 0,
		});
		expect(items[0].blob).toBeInstanceOf(Blob);
	});

	test('listPending filters by pageId', async () => {
		await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });
		await enqueue({ pageId: 2, blob: blob(), name: 'b.jpg' });

		const onlyOne = await listPending(1);
		expect(onlyOne).toHaveLength(1);
		expect(onlyOne[0].name).toBe('a.jpg');
	});

	test('listPending excludes done items', async () => {
		const id = await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });
		await markDone(id);

		const items = await listPending(1);
		expect(items).toHaveLength(0);
	});

	test('markFailed increments attempts and records error', async () => {
		const id = await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });
		await markFailed(id, 'network');
		await markFailed(id, 'network');

		const items = await listPending(1);
		expect(items[0].attempts).toBe(2);
		expect(items[0].lastError).toBe('network');
		expect(items[0].status).toBe('failed');
	});

	test('openQueue is retryable after an open failure', async () => {
		// Without clearing the cached promise on failure, a transient
		// IDB open error would pin `dbPromise` to a rejected promise and
		// every subsequent call would resurface the same rejection — even
		// across resetForTests. Force one failure, restore the real
		// implementation, and assert that the next call succeeds.
		const openSpy = jest.spyOn(globalThis.indexedDB, 'open').mockImplementationOnce(() => {
			const req = {
				onsuccess: null,
				onerror: null,
				onupgradeneeded: null,
				error: new Error('forced failure'),
				result: null,
			};
			// Fire onerror asynchronously to mimic the real IDBRequest contract.
			queueMicrotask(() => {
				if (typeof req.onerror === 'function') {
					req.onerror({ target: req });
				}
			});
			return req;
		});

		await expect(openQueue()).rejects.toThrow('forced failure');

		// Restore the real implementation. The next call must rebuild the
		// promise from scratch — which only happens if the failure path
		// nulled the cached promise.
		openSpy.mockRestore();

		const db = await openQueue();
		expect(db.name).toBe('pixfete-uploads');
		expect(db.objectStoreNames.contains('queue')).toBe(true);
	});
});
