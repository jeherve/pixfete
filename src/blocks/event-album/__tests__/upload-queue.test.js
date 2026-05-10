/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// jsdom doesn't expose `structuredClone` on its window/global, but
// fake-indexeddb relies on it to snapshot stored values (including Blobs).
// Provide a shallow-but-typed clone that preserves Blob identity for the
// payloads this queue stores — sufficient for these tests, and only
// installed when the runtime is missing the real implementation.
if (typeof globalThis.structuredClone !== 'function') {
	globalThis.structuredClone = (value) => {
		if (value instanceof Blob) {
			return value.slice(0, value.size, value.type);
		}
		if (Array.isArray(value)) {
			return value.map((v) => globalThis.structuredClone(v));
		}
		if (value && typeof value === 'object') {
			const out = {};
			for (const key of Object.keys(value)) {
				out[key] = globalThis.structuredClone(value[key]);
			}
			return out;
		}
		return value;
	};
}

import 'fake-indexeddb/auto';

import { openQueue, enqueue, listPending, markDone, markFailed, deleteItem, resetForTests } from '../upload-queue';

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

	test('deleteItem removes the record entirely', async () => {
		const id = await enqueue({ pageId: 1, blob: blob(), name: 'a.jpg' });
		await deleteItem(id);

		const items = await listPending(1);
		expect(items).toHaveLength(0);
	});
});
