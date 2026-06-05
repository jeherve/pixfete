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

/**
 * How long (ms) a claimed record is hidden from other drainers.
 *
 * The in-page drain and the Service Worker drain run in separate JS
 * realms over this one store; claiming a record before uploading it
 * stops them double-POSTing the same blob. If the drainer that claimed
 * a record dies mid-upload (tab closed, SW killed) the claim would
 * otherwise strand the record forever, so the claim is a *lease*: once
 * it goes stale the record becomes claimable again and the upload
 * recovers. Two minutes comfortably outlasts a normal photo POST while
 * still recovering a genuinely dead drainer reasonably quickly.
 *
 * @type {number}
 */
export const CLAIM_LEASE_MS = 2 * 60 * 1000;

let dbPromise = null;

/**
 * Open (and lazily upgrade) the queue database.
 *
 * Reuses a single IDBDatabase across calls in the page, since opening
 * the connection is the expensive part. The store has an `id` keyPath
 * with autoincrement and an index on `pageId` so listPending is a fast
 * range query.
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
		req.onsuccess = () => {
			const db = req.result;
			// If a future version bump is opened by another realm (most
			// commonly the Service Worker), close our connection so the
			// upgrade isn't blocked. We also null the cached promise so
			// the next call reopens at the new version instead of reusing
			// a closed handle.
			db.onversionchange = () => {
				db.close();
				dbPromise = null;
			};
			resolve(db);
		};
		req.onerror = () => {
			// Clear the cached promise so callers can retry. Otherwise a
			// transient failure (private-browsing quota, storage corruption)
			// would pin a rejected promise forever and every subsequent call
			// would resurface the same error — even after resetForTests.
			dbPromise = null;
			reject(req.error);
		};
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
 * `restBase` is captured at enqueue time so the Service Worker can
 * POST to the correct REST URL on subdirectory and subdir-multisite
 * installs (where the SW's `registration.scope` doesn't carry enough
 * information to reconstruct the right `/wp-json/pixfete/v1` path).
 *
 * @param {{pageId: number, blob: Blob, name: string, restBase: string}} item Upload payload.
 * @return {Promise<number>} The autoincremented id of the new record.
 */
export async function enqueue({ pageId, blob, name, restBase }) {
	const db = await openQueue();
	const tx = db.transaction(STORE, 'readwrite');
	const store = tx.objectStore(STORE);
	const id = await promisify(
		store.add({
			pageId,
			blob,
			name,
			restBase,
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
 * Atomically claim the oldest uploadable record for a page.
 *
 * The in-page drain (view.js) and the Service Worker drain (sw.js) both
 * pull work from this store but run in separate JS realms with no shared
 * memory. Picking the next record with a plain `listPending` + take-first
 * lets them grab the *same* 'pending' record and POST it concurrently —
 * the server then stores the photo twice. Claiming closes that race: the
 * read and the claiming write happen inside a single readwrite
 * transaction (IndexedDB serializes those across realms), so whichever
 * drainer wins the transaction stamps `claimedAt` and the other sees the
 * record as taken and moves on.
 *
 * Only 'pending' records are claimable. A record claimed within the last
 * {@link CLAIM_LEASE_MS} is skipped; past that its lease is treated as
 * stale (the drainer holding it likely died) and it can be reclaimed.
 *
 * Implemented with a cursor rather than getAll+put so the read and the
 * update share one transaction without an `await` in between — awaiting a
 * microtask mid-transaction would let IndexedDB auto-commit and the write
 * would then throw.
 *
 * @param {number} pageId Page to claim from.
 * @return {Promise<Object|null>} The claimed record, or null if none are claimable.
 */
export async function claimNext(pageId) {
	const db = await openQueue();
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE, 'readwrite');
		const req = tx.objectStore(STORE).index('pageId').openCursor(pageId);
		const now = Date.now();
		req.onsuccess = () => {
			const cursor = req.result;
			if (!cursor) {
				resolve(null);
				return;
			}
			const item = cursor.value;
			const claimable = item.status === 'pending' && (!item.claimedAt || now - item.claimedAt > CLAIM_LEASE_MS);
			if (claimable) {
				item.claimedAt = now;
				const update = cursor.update(item);
				update.onsuccess = () => resolve(item);
				update.onerror = () => reject(update.error);
				return;
			}
			cursor.continue();
		};
		req.onerror = () => reject(req.error);
	});
}

/**
 * Release a claim so the record can be drained again.
 *
 * Called when an upload attempt failed at the network layer: the record
 * is unfinished, not rejected, so it returns to the claimable pool for
 * the next drain (or Background Sync) instead of being held for the
 * remainder of its lease.
 *
 * @param {number} id Queue record id.
 * @return {Promise<void>}
 */
export async function releaseClaim(id) {
	const db = await openQueue();
	const tx = db.transaction(STORE, 'readwrite');
	const store = tx.objectStore(STORE);
	const item = await promisify(store.get(id));
	if (!item) {
		return;
	}
	item.claimedAt = null;
	await promisify(store.put(item));
}

/**
 * Reset every 'failed' record on a page back to 'pending'.
 *
 * Used when the guest taps "Retry uploads" — we want a clean slate
 * for the in-page drain (and Background Sync) to try again.
 *
 * @param {number} pageId Page whose failures should be requeued.
 * @return {Promise<void>}
 */
export async function requeueFailed(pageId) {
	const db = await openQueue();
	const tx = db.transaction(STORE, 'readwrite');
	const idx = tx.objectStore(STORE).index('pageId');
	const items = await promisify(idx.getAll(pageId));
	for (const item of items) {
		if (item.status !== 'failed') {
			continue;
		}
		item.status = 'pending';
		item.attempts = 0;
		item.lastError = null;
		item.claimedAt = null;
		await promisify(tx.objectStore(STORE).put(item));
	}
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
