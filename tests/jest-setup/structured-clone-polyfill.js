/**
 * Polyfill `structuredClone` for the jsdom test environment.
 *
 * jsdom@26 paired with jest-environment-jsdom@30 does not expose
 * `structuredClone` on its window/global. fake-indexeddb relies on it
 * to snapshot stored values (including Blobs) when records are written
 * or read back, so without this polyfill any IndexedDB test that stores
 * a Blob fails with "structuredClone is not a function".
 *
 * Loaded via `setupFiles` in jest.config.js so it is in place before
 * fake-indexeddb's auto-setup (which runs as a regular module import in
 * each test file) reaches for the global.
 *
 * The implementation is intentionally minimal — a recursive walk that
 * preserves Blob identity and clones plain objects/arrays. It is not a
 * complete spec-compliant structuredClone (no Map, Set, typed arrays,
 * cycles), but it's sufficient for the payload shapes our code stores.
 */
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
