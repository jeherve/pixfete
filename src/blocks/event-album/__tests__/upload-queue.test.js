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

// Rewrite a record's claim timestamp directly so a stale lease can be
// simulated without faking timers (which would interfere with
// fake-indexeddb's transaction scheduling).
async function backdateClaim(id, claimedAt) {
	const db = await openQueue();
	const tx = db.transaction('queue', 'readwrite');
	const store = tx.objectStore('queue');
	const item = await new Promise((resolve, reject) => {
		const req = store.get(id);
		req.onsuccess = () => resolve(req.result);
		req.onerror = () => reject(req.error);
	});
	item.claimedAt = claimedAt;
	await new Promise((resolve, reject) => {
		const req = store.put(item);
		req.onsuccess = () => resolve();
		req.onerror = () => reject(req.error);
	});
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

	test('requeueFailed resets attempts and status for failed items only', async () => {
		const { requeueFailed } = require('../upload-queue');
		const id1 = await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });
		const id2 = await enqueue({ pageId: 1, blob: blob(), name: 'b.jpg' });
		await markFailed(id1, 'network');
		// id2 stays pending

		await requeueFailed(1);

		const items = await listPending(1);
		const map = Object.fromEntries(items.map((i) => [i.id, i]));
		expect(map[id1].status).toBe('pending');
		expect(map[id1].attempts).toBe(0);
		expect(map[id1].lastError).toBeNull();
		expect(map[id2].status).toBe('pending');
	});
});

describe('claimNext / releaseClaim coordinate the two drainers', () => {
	test('claimNext returns the oldest pending record and stamps a claim', async () => {
		const { claimNext } = require('../upload-queue');
		const first = await enqueue({ pageId: 1, blob: blob('a'), name: 'a.jpg' });
		await enqueue({ pageId: 1, blob: blob('b'), name: 'b.jpg' });

		const claimed = await claimNext(1);
		expect(claimed.id).toBe(first);
		expect(typeof claimed.claimedAt).toBe('number');
	});

	test('a freshly claimed record is not handed to the next claim', async () => {
		// The in-page drain and the Service Worker drain run in separate JS
		// realms over the same IndexedDB store. Without a claim they both read
		// the same 'pending' record and POST it concurrently, producing a
		// duplicate photo on the server. The second claim must skip a record
		// the first claim already took.
		const { claimNext } = require('../upload-queue');
		const first = await enqueue({ pageId: 1, blob: blob('a'), name: 'a.jpg' });
		const second = await enqueue({ pageId: 1, blob: blob('b'), name: 'b.jpg' });

		const a = await claimNext(1);
		const b = await claimNext(1);

		expect(a.id).toBe(first);
		expect(b.id).toBe(second);

		// Both are in flight now; nothing is left to double-claim.
		expect(await claimNext(1)).toBeNull();
	});

	test('claimNext skips failed records', async () => {
		const { claimNext } = require('../upload-queue');
		const id = await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });
		await markFailed(id, 'http');

		expect(await claimNext(1)).toBeNull();
	});

	test('claimNext reclaims a record whose lease has gone stale', async () => {
		// A drainer that dies mid-upload (tab closed, SW killed) leaves the
		// record claimed. Once the lease expires the record must become
		// claimable again so the upload still recovers instead of being
		// stranded 'pending' but permanently un-claimable.
		const { claimNext } = require('../upload-queue');
		const id = await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });

		await claimNext(1);
		expect(await claimNext(1)).toBeNull(); // fresh lease blocks reclaim

		await backdateClaim(id, Date.now() - 10 * 60 * 1000);

		const reclaimed = await claimNext(1);
		expect(reclaimed.id).toBe(id);
	});

	test('releaseClaim returns a record to the claimable pool', async () => {
		const { claimNext, releaseClaim } = require('../upload-queue');
		const id = await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });

		const claimed = await claimNext(1);
		expect(claimed.id).toBe(id);
		expect(await claimNext(1)).toBeNull();

		await releaseClaim(id);

		const reclaimed = await claimNext(1);
		expect(reclaimed.id).toBe(id);
	});
});
