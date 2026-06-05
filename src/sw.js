/* global self */
/**
 * Pixfête Service Worker.
 *
 * Primary responsibility: drain the IndexedDB upload queue when the
 * Background Sync API fires, so photos uploaded on flaky networks
 * (or with the tab closed) land safely.
 *
 * The SW deliberately does not cache album photos — caching guest
 * photos for offline read raises privacy questions we don't want to
 * answer in this iteration. The fetch listener at the bottom of this
 * file therefore passes navigations straight through to the network
 * and ignores every other request. Its sole purpose is to satisfy
 * Chrome's installability check: without a non-trivial fetch handler
 * registered on the controlling SW, Chrome will not fire
 * `beforeinstallprompt` and the "install this album" prompt never
 * appears — which was the symptom on managed hosts even after the
 * SW URL fix.
 */

import { claimNext, markDone, markFailed, openQueue, releaseClaim } from './blocks/event-album/upload-queue';

const SYNC_TAG = 'pixfete-upload-queue';

/**
 * Pull every page's pending items off IDB, attempt them, and notify clients.
 *
 * Uploads happen sequentially; on the first failure we stop draining for
 * that page so a single broken file doesn't burn the rest into the same
 * failure mode. The browser will fire `sync` again on its own schedule.
 *
 * Each queue record carries its own `restBase` (stashed by the page at
 * enqueue time) so the SW doesn't have to derive a REST URL from
 * `self.registration.scope` — that path drops subdirectory prefixes and
 * silently misroutes uploads on subdirectory and subdir-multisite sites.
 *
 * Items missing a `restBase` are marked failed instead of POSTed: the
 * record predates the field (an upgrade case) and we'd rather surface
 * the failure than guess an origin and route uploads to the wrong site.
 *
 * Each record is claimed (see `claimNext`) before its POST so this drain
 * and the page's in-page drain can't both upload the same record and
 * create a duplicate photo when they happen to run at the same time.
 *
 * @return {Promise<void>}
 */
export async function drainQueue() {
	const allPages = await collectPendingPageIds();

	for (const pageId of allPages) {
		let item = await claimNext(pageId);
		while (item) {
			if (!item.restBase) {
				await markFailed(item.id, 'no-rest-base');
				await broadcast({
					type: 'pixfete:upload-failed',
					queueId: item.id,
					pageId,
				});
				break;
			}
			let response;
			try {
				const formData = new FormData();
				formData.append('photo', item.blob, item.name);
				response = await fetch(`${item.restBase}/photos/${pageId}`, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData,
				});
			} catch (err) {
				// Network-layer failure — the request never reached the server,
				// so the upload is genuinely unfinished. Release the claim and
				// leave the record 'pending' (don't mark it 'failed') so the
				// next `sync` event retries it. Reject the drain after cleanup:
				// Background Sync only knows to schedule another attempt when
				// the promise passed to event.waitUntil() fails.
				await releaseClaim(item.id);
				throw err;
			}
			if (!response.ok) {
				await markFailed(item.id, 'http');
				await broadcast({
					type: 'pixfete:upload-failed',
					queueId: item.id,
					pageId,
				});
				break;
			}
			// The server accepted the upload; from here on, failures are
			// post-success bookkeeping problems. Don't mark failed (which would
			// auto-retry and produce duplicate photos): log so devs see them,
			// remove the blob, and notify the page if we can parse the photo.
			let photo = null;
			try {
				photo = await response.json();
			} catch (err) {
				// Caching plugin / CDN / WAF intercepted with a non-JSON body.
				// The upload itself succeeded, so we can't safely re-attempt.
				// eslint-disable-next-line no-console -- aids debugging without changing UX.
				console.warn('Pixfête SW: upload succeeded but response was not JSON', err);
			}
			await markDone(item.id);
			await broadcast({
				type: photo ? 'pixfete:upload-success' : 'pixfete:upload-done-opaque',
				queueId: item.id,
				pageId,
				photo,
			});
			item = await claimNext(pageId);
		}
	}
}

/**
 * Read every record's pageId once so we can group queue work by event.
 *
 * @return {Promise<number[]>} Distinct pageIds with at least one queued upload.
 */
async function collectPendingPageIds() {
	const db = await openQueue();
	const tx = db.transaction('queue', 'readonly');
	return new Promise((resolve, reject) => {
		const ids = new Set();
		const req = tx.objectStore('queue').openCursor();
		req.onsuccess = () => {
			const cursor = req.result;
			if (cursor) {
				ids.add(cursor.value.pageId);
				cursor.continue();
			} else {
				resolve([...ids]);
			}
		};
		req.onerror = () => reject(req.error);
	});
}

/**
 * Post a message to every controlled client.
 *
 * @param {Object} message Payload mirroring the page's expectations.
 * @return {Promise<void>}
 */
async function broadcast(message) {
	const clients = await self.clients.matchAll({ includeUncontrolled: true });
	for (const client of clients) {
		client.postMessage(message);
	}
}

self.addEventListener('install', () => {
	self.skipWaiting();
});

self.addEventListener('activate', (event) => {
	event.waitUntil(self.clients.claim());
});

self.addEventListener('sync', (event) => {
	if (event.tag !== SYNC_TAG) {
		return;
	}
	event.waitUntil(drainQueue());
});

// Fetch handler exists purely to satisfy Chrome's PWA install criteria.
// Chrome will not fire `beforeinstallprompt` unless the controlling SW
// has a fetch listener AND that listener actually intercepts something
// (no-op handlers are detected via static analysis and ignored). We
// don't want to cache anything (see the file docblock), so we only
// route navigation requests through the SW and pass them straight to
// the network. Every other request (subresources, REST API, polling)
// is left to the browser's default handling.
self.addEventListener('fetch', (event) => {
	if (event.request.mode === 'navigate') {
		event.respondWith(fetch(event.request));
	}
});
