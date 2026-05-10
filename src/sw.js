/* global self */
/**
 * Pixfête Service Worker.
 *
 * Sole responsibility: drain the IndexedDB upload queue when the
 * Background Sync API fires, so photos uploaded on flaky networks
 * (or with the tab closed) land safely.
 *
 * The SW is deliberately not a fetch interceptor — caching album
 * photos for offline read access raises privacy questions we don't
 * want to answer in this iteration.
 */

import { listPending, markDone, markFailed, openQueue } from './blocks/event-album/upload-queue';

const SYNC_TAG = 'pixfete-upload-queue';

/**
 * Pull every page's pending items off IDB, attempt them, and notify clients.
 *
 * Uploads happen sequentially; on the first failure we stop draining for
 * that page so a single broken file doesn't burn the rest into the same
 * failure mode. The browser will fire `sync` again on its own schedule.
 *
 * @param {string} restBase Fully-qualified Pixfête REST namespace URL.
 * @return {Promise<void>}
 */
export async function drainQueue(restBase) {
	const allPages = await collectPendingPageIds();

	for (const pageId of allPages) {
		const pending = await listPending(pageId);
		for (const item of pending) {
			if (item.status === 'failed') {
				continue;
			}
			try {
				const formData = new FormData();
				formData.append('photo', item.blob, item.name);
				const response = await fetch(`${restBase}/photos/${pageId}`, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData,
				});
				if (!response.ok) {
					await markFailed(item.id, 'http');
					await broadcast({
						type: 'pixfete:upload-failed',
						queueId: item.id,
						pageId,
					});
					break;
				}
				const photo = await response.json();
				await markDone(item.id);
				await broadcast({
					type: 'pixfete:upload-success',
					queueId: item.id,
					pageId,
					photo,
				});
			} catch {
				await markFailed(item.id, 'network');
				await broadcast({
					type: 'pixfete:upload-failed',
					queueId: item.id,
					pageId,
				});
				break;
			}
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
	const restBase = self.registration?.scope
		? `${new URL(self.registration.scope).origin}/wp-json/pixfete/v1`
		: '/wp-json/pixfete/v1';
	event.waitUntil(drainQueue(restBase));
});
