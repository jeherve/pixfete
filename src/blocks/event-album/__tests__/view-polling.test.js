/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

const i18nFixture = require('../__fixtures__/i18n');

let mockRegisteredStore = {};
let mockContext = {};
jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			if (definition) {
				mockRegisteredStore = definition;
			}
			return mockRegisteredStore;
		},
		getContext: () => mockContext,
	}),
	{ virtual: true }
);

beforeEach(() => {
	jest.resetModules();
	jest.useFakeTimers();
	mockRegisteredStore = {};
	mockContext = {
		pageId: 42,
		restBase: 'https://example.test/wp-json/pixfete/v1',
		dateStart: '',
		dateEnd: '',
		i18n: { ...i18nFixture },
	};
	global.fetch = jest.fn();
});

afterEach(() => {
	jest.useRealTimers();
	delete global.fetch;
});

function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

const POLL_INTERVAL = 15000;

describe('startPolling on an album that was empty at load', () => {
	test('polls without a `since` param so empty-album guests still receive uploads', async () => {
		// Regression: guests who opened the album while it had no visible
		// photos were pinned at latestUploadedAt === 0, and the poll bailed
		// out on `if (!state.latestUploadedAt) return;` — so they never saw
		// any photo uploaded after they arrived until they reloaded the page.
		// This is the "some guests see the photos, some don't" half of the
		// bug: the split was simply who loaded the album before vs. after the
		// first photo existed.
		const def = loadStore();
		def.state.photos = [];
		def.state.latestUploadedAt = 0;

		global.fetch.mockResolvedValueOnce({
			ok: true,
			headers: { get: () => '1' },
			json: async () => [{ id: 5, full: 'u', thumbnail: 't', guest_name: 'g', uploaded_at: 123 }],
		});

		def.actions.startPolling();
		await jest.advanceTimersByTimeAsync(POLL_INTERVAL);

		expect(global.fetch).toHaveBeenCalledTimes(1);
		const requestedUrl = global.fetch.mock.calls[0][0];
		expect(requestedUrl).not.toContain('since=');
		// The new photo lands in the banner queue and advances the watermark.
		expect(def.state.pendingPhotos.map((p) => p.id)).toEqual([5]);
		expect(def.state.newPhotoCount).toBe(1);
		expect(def.state.latestUploadedAt).toBe(123);
	});

	test('uses the `since` param once a watermark exists', async () => {
		const def = loadStore();
		def.state.photos = [{ id: 1, uploaded_at: 100 }];
		def.state.latestUploadedAt = 100;

		global.fetch.mockResolvedValueOnce({
			ok: true,
			headers: { get: () => '1' },
			json: async () => [],
		});

		def.actions.startPolling();
		await jest.advanceTimersByTimeAsync(POLL_INTERVAL);

		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(global.fetch.mock.calls[0][0]).toContain('since=100');
	});
});
