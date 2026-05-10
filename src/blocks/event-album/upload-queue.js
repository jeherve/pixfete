/**
 * IndexedDB-backed upload queue for the Event Photo Album block.
 *
 * Each enqueued record carries the raw Blob, the page it belongs to,
 * and bookkeeping (attempts, status, error). The queue is the single
 * source of truth for "what still needs to be uploaded" — both the
 * in-page retry loop and the Service Worker drain through this API.
 *
 * The module is intentionally fetch-free: callers own the network call
 * and report success/failure back via markDone/markFailed.
 */

const DB_NAME = 'pixfete-uploads';
const DB_VERSION = 1;
const STORE = 'queue';

let dbPromise = null;

/**
 * Open (and lazily upgrade) the queue database.
 *
 * Reuses a single IDBDatabase across calls in the page, since opening
 * the connection is the expensive part. The store has an autoincrement
 * keyPath and an index on `pageId` so listPending is a fast range query.
 *
 * @return {Promise<IDBDatabase>} Resolved DB handle.
 */
export function openQueue() {
	if (dbPromise) {
		return dbPromise;
	}
	dbPromise = new Promise((resolve, reject) => {
		const req = globalThis.indexedDB.open(DB_NAME, DB_VERSION);
		req.onupgradeneeded = () => {
			const db = req.result;
			if (!db.objectStoreNames.contains(STORE)) {
				const store = db.createObjectStore(STORE, {
					keyPath: 'id',
					autoIncrement: true,
				});
				store.createIndex('pageId', 'pageId', { unique: false });
			}
		};
		req.onsuccess = () => resolve(req.result);
		req.onerror = () => reject(req.error);
	});
	return dbPromise;
}

/**
 * Wrap an IDBRequest in a promise.
 *
 * IndexedDB's event-driven API is awkward to compose; this helper lets
 * the rest of the module read top-to-bottom as straightforward async code.
 *
 * @param {IDBRequest} req The IndexedDB request.
 * @return {Promise<*>} Resolves with req.result.
 */
function promisify(req) {
	return new Promise((resolve, reject) => {
		req.onsuccess = () => resolve(req.result);
		req.onerror = () => reject(req.error);
	});
}

/**
 * Persist a new pending upload.
 *
 * Stores the original Blob alongside metadata so the consumer can
 * reconstruct a multipart request later — including after a page
 * reload or while offline — without needing the original File handle.
 *
 * @param {{pageId: number, blob: Blob, name: string}} item Upload payload.
 * @return {Promise<number>} The autoincremented id of the new record.
 */
export async function enqueue({ pageId, blob, name }) {
	const db = await openQueue();
	const tx = db.transaction(STORE, 'readwrite');
	const store = tx.objectStore(STORE);
	const id = await promisify(
		store.add({
			pageId,
			blob,
			name,
			type: blob.type,
			size: blob.size,
			status: 'pending',
			attempts: 0,
			lastError: null,
			createdAt: Date.now(),
		})
	);
	return Number(id);
}

/**
 * Return all unfinished records for a page, oldest first.
 *
 * "Unfinished" means status !== 'done'; failed records are still
 * returned so the consumer can decide whether to retry them.
 *
 * @param {number} pageId Filter to this page.
 * @return {Promise<Array<Object>>} Pending and failed items.
 */
export async function listPending(pageId) {
	const db = await openQueue();
	const tx = db.transaction(STORE, 'readonly');
	const idx = tx.objectStore(STORE).index('pageId');
	const all = await promisify(idx.getAll(pageId));
	return all.filter((item) => item.status !== 'done');
}

/**
 * Mark an upload as completed and remove its blob to free storage.
 *
 * The record itself is deleted rather than kept in a 'done' state —
 * we have no need for an upload history; the WordPress media library
 * is the durable record. Holding onto blobs would also bloat the
 * IndexedDB quota for hosts who shoot hundreds of photos.
 *
 * @param {number} id Queue record id.
 * @return {Promise<void>}
 */
export async function markDone(id) {
	const db = await openQueue();
	const tx = db.transaction(STORE, 'readwrite');
	await promisify(tx.objectStore(STORE).delete(id));
}

/**
 * Record a failed attempt, bumping `attempts` and storing the error.
 *
 * We keep the record around (rather than deleting it) so the consumer
 * can implement backoff/retry policy, surface the failure to the user,
 * and let the Service Worker pick it up on the next sync event.
 *
 * @param {number} id        Queue record id.
 * @param {string} lastError Short error label (e.g. 'network').
 * @return {Promise<void>}
 */
export async function markFailed(id, lastError) {
	const db = await openQueue();
	const tx = db.transaction(STORE, 'readwrite');
	const store = tx.objectStore(STORE);
	const item = await promisify(store.get(id));
	if (!item) {
		return;
	}
	item.attempts += 1;
	item.lastError = lastError;
	item.status = 'failed';
	await promisify(store.put(item));
}

/**
 * Delete a record outright (e.g. user dismissed a permanent failure).
 *
 * Aliased to markDone because both operations have the same effect on
 * storage — the difference is purely intent at the call site, which
 * the consumer can express through naming. Kept as a separate export
 * so future divergence (e.g. emitting a 'cancelled' analytics event)
 * doesn't require touching every caller.
 *
 * @param {number} id Queue record id.
 * @return {Promise<void>}
 */
export async function deleteItem(id) {
	return markDone(id);
}

/**
 * Test helper — drop the cached DB handle and clear the store.
 *
 * Each test starts from an empty store; without this, fake-indexeddb's
 * in-memory state would leak between tests and break isolation. The
 * onblocked handler resolves rather than rejects so a lingering open
 * connection in a previous test doesn't hang the suite.
 *
 * @return {Promise<void>}
 */
export async function resetForTests() {
	if (dbPromise) {
		const db = await dbPromise;
		db.close();
	}
	dbPromise = null;
	await new Promise((resolve, reject) => {
		const req = globalThis.indexedDB.deleteDatabase(DB_NAME);
		req.onsuccess = () => resolve();
		req.onerror = () => reject(req.error);
		req.onblocked = () => resolve();
	});
}
