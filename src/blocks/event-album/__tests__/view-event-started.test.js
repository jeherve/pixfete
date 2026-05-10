/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock @wordpress/interactivity with a configurable getContext.
let mockRegisteredStore = {};
let mockContext = {
	pageId: 1,
	restBase: '/wp-json/pixfete/v1',
	dateStart: '',
	dateEnd: '',
};
jest.mock(
	'@wordpress/interactivity',
	() => ({
		store: (_name, definition) => {
			// Re-using the same store name without a definition (e.g.
			// `store('pixfete')` inside an action) must return the
			// previously registered store rather than overwrite it.
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
	mockRegisteredStore = {};
	mockContext = {
		pageId: 1,
		restBase: '/wp-json/pixfete/v1',
		dateStart: '',
		dateEnd: '',
	};
	jest.resetModules();
});

function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

describe('isEventStarted', () => {
	test('returns true when dateStart is not set', () => {
		const store = loadStore();
		expect(store.state.isEventStarted).toBe(true);
	});

	test('returns true when today equals dateStart', () => {
		const today = new Date().toISOString().substring(0, 10);
		mockContext.dateStart = today;
		const store = loadStore();
		expect(store.state.isEventStarted).toBe(true);
	});

	test('returns true when dateStart is in the past', () => {
		mockContext.dateStart = '2020-01-01';
		const store = loadStore();
		expect(store.state.isEventStarted).toBe(true);
	});

	test('returns false when dateStart is in the future', () => {
		mockContext.dateStart = '2099-12-31';
		const store = loadStore();
		expect(store.state.isEventStarted).toBe(false);
	});
});

describe('isNotStartedView', () => {
	test('returns true when currentView is not-started', () => {
		const store = loadStore();
		store.state.currentView = 'not-started';
		expect(store.state.isNotStartedView).toBe(true);
	});

	test('returns false when currentView is something else', () => {
		const store = loadStore();
		store.state.currentView = 'gallery';
		expect(store.state.isNotStartedView).toBe(false);
	});
});

describe('isUploadEnabled', () => {
	test('returns false when dateStart is in the future', () => {
		mockContext.dateStart = '2099-12-31';
		const store = loadStore();
		expect(store.state.isUploadEnabled).toBe(false);
	});

	test('returns true when dateStart is today', () => {
		const today = new Date().toISOString().substring(0, 10);
		mockContext.dateStart = today;
		const store = loadStore();
		expect(store.state.isUploadEnabled).toBe(true);
	});

	test('returns false when dateEnd is in the past', () => {
		mockContext.dateEnd = '2020-01-01';
		const store = loadStore();
		expect(store.state.isUploadEnabled).toBe(false);
	});

	test('returns true when both dateStart and dateEnd are absent', () => {
		const store = loadStore();
		expect(store.state.isUploadEnabled).toBe(true);
	});
});

/**
 * Drive a generator returned by an Interactivity action to completion,
 * resolving each yielded promise as the runtime would.
 *
 * @param {Object} gen The generator to drive.
 * @return {Promise} Resolves when the generator returns.
 */
async function runGenerator(gen) {
	let result = gen.next();
	while (!result.done) {
		try {
			const value = await result.value;
			result = gen.next(value);
		} catch (error) {
			result = gen.throw(error);
		}
	}
	return result.value;
}

describe('init() with future event', () => {
	beforeEach(() => {
		// Stub browser APIs that init() uses.
		delete global.document.cookie;
		Object.defineProperty(global.document, 'cookie', {
			value: '',
			writable: true,
			configurable: true,
		});
		// Stub window.history.replaceState for cleanUrlParams. JSDOM's default
		// location (http://localhost/) is sufficient for these tests since
		// they don't depend on any query parameters.
		window.history.replaceState = jest.fn();

		// init() now fetches a CSRF token from the REST /token endpoint
		// before transitioning to a non-loading view. Stub a successful
		// response by default; tests that need a different outcome
		// override this.
		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: true,
				json: () => Promise.resolve({ nonce: 'fresh-token' }),
			})
		);
	});

	afterEach(() => {
		delete global.fetch;
	});

	test('sets currentView to not-started when event is in the future', async () => {
		mockContext.dateStart = '2099-12-31';
		const store = loadStore();
		await runGenerator(store.actions.init());
		expect(store.state.currentView).toBe('not-started');
		// Future events short-circuit before the token fetch.
		expect(global.fetch).not.toHaveBeenCalled();
	});

	test('does not set not-started when event has started', async () => {
		mockContext.dateStart = '2020-01-01';
		const store = loadStore();
		await runGenerator(store.actions.init());
		// Should proceed to password view (no cookie, no key param).
		expect(store.state.currentView).toBe('password');
	});

	test('does not set not-started when no dateStart is set', async () => {
		const store = loadStore();
		await runGenerator(store.actions.init());
		expect(store.state.currentView).toBe('password');
	});
});

describe('init() token fetch', () => {
	beforeEach(() => {
		delete global.document.cookie;
		Object.defineProperty(global.document, 'cookie', {
			value: '',
			writable: true,
			configurable: true,
		});
		window.history.replaceState = jest.fn();
	});

	afterEach(() => {
		delete global.fetch;
	});

	test('populates ctx.nonce from the /token endpoint before transitioning', async () => {
		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: true,
				json: () => Promise.resolve({ nonce: 'server-issued-token' }),
			})
		);

		const store = loadStore();
		await runGenerator(store.actions.init());

		expect(global.fetch).toHaveBeenCalledWith(
			'/wp-json/pixfete/v1/token/1',
			expect.objectContaining({ credentials: 'same-origin' })
		);
		expect(mockContext.nonce).toBe('server-issued-token');
		expect(store.state.currentView).toBe('password');
	});

	test('stays on loading view with an error message when token fetch fails', async () => {
		global.fetch = jest.fn(() => Promise.resolve({ ok: false }));

		const store = loadStore();
		await runGenerator(store.actions.init());

		expect(store.state.currentView).toBe('loading');
		expect(store.state.errorMessage).toBeTruthy();
	});

	test('stays on loading view with an error message when fetch throws', async () => {
		global.fetch = jest.fn(() => Promise.reject(new Error('offline')));

		const store = loadStore();
		await runGenerator(store.actions.init());

		expect(store.state.currentView).toBe('loading');
		expect(store.state.errorMessage).toBeTruthy();
	});

	test('skips the token fetch when a consented cookie is already present', async () => {
		// Mark cookie consent=true; readCookie() decodes a base64url JSON
		// payload, then a dot, then ignores everything after the dot.
		const payload = btoa(JSON.stringify({ consent: true, page_id: 1 }));
		Object.defineProperty(global.document, 'cookie', {
			value: `pixfete_1=${payload}.signature`,
			writable: true,
			configurable: true,
		});

		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: true,
				json: () => Promise.resolve([]),
				headers: { get: () => '1' },
			})
		);

		const store = loadStore();
		await runGenerator(store.actions.init());

		expect(store.state.currentView).toBe('gallery');
		// Photos endpoint is hit, but /token must not be — the consented
		// gallery path doesn't need a CSRF token.
		const tokenCalls = global.fetch.mock.calls.filter((call) => String(call[0]).includes('/token/'));
		expect(tokenCalls).toHaveLength(0);
	});
});

describe('submitPassword auto-retry on CSRF failure', () => {
	beforeEach(() => {
		delete global.document.cookie;
		Object.defineProperty(global.document, 'cookie', {
			value: '',
			writable: true,
			configurable: true,
		});
		window.history.replaceState = jest.fn();
	});

	afterEach(() => {
		delete global.fetch;
	});

	test('retries once with the fresh nonce returned by the server', async () => {
		mockContext.nonce = 'stale-nonce';
		mockContext.honeypotField = 'email';

		// First call: invalid-nonce error with a fresh token attached.
		// Second call: success.
		global.fetch = jest
			.fn()
			.mockImplementationOnce(() =>
				Promise.resolve({
					ok: false,
					json: () =>
						Promise.resolve({
							code: 'pixfete_invalid_nonce',
							message: 'The CSRF token is invalid or has expired.',
							data: { status: 403, nonce: 'recovered-nonce' },
						}),
				})
			)
			.mockImplementationOnce(() =>
				Promise.resolve({
					ok: true,
					json: () => Promise.resolve({ valid: true, nonce: 'next-step-nonce' }),
				})
			);

		const store = loadStore();
		store.state.passwordInput = 'hunter2';

		await runGenerator(store.actions.submitPassword({ preventDefault: () => {} }));

		expect(global.fetch).toHaveBeenCalledTimes(2);
		expect(store.state.currentView).toBe('registration');
		// User should not see a CSRF error after a successful auto-retry.
		expect(store.state.errorMessage).toBe('');
	});

	test('does not retry on a non-CSRF error (e.g. wrong password)', async () => {
		mockContext.nonce = 'good-nonce';
		mockContext.honeypotField = 'email';

		global.fetch = jest.fn(() =>
			Promise.resolve({
				ok: false,
				json: () =>
					Promise.resolve({
						code: 'pixfete_invalid_password',
						message: 'The password is incorrect.',
						data: { status: 403, nonce: 'next-attempt-nonce' },
					}),
			})
		);

		const store = loadStore();
		store.state.passwordInput = 'wrong';

		await runGenerator(store.actions.submitPassword({ preventDefault: () => {} }));

		expect(global.fetch).toHaveBeenCalledTimes(1);
		expect(store.state.errorMessage).toBe('The password is incorrect.');
	});
});
