/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock @wordpress/interactivity with a configurable getContext.
let mockRegisteredStore = {};
let mockContext = {
	eventPageId: 1,
	eventVersion: 1,
	restBase: '/wp-json/pixfete/v1',
	dateStart: '',
	interval: 5,
	honeypotField: 'website',
	nonce: 'test-nonce',
	i18n: {
		passwordIncorrect: 'The password is incorrect.',
		networkError: 'A network error occurred.',
		initFailed: 'Could not initialize. Please try again.',
		initConnectionFailed: 'Could not initialize. Please check your connection and try again.',
	},
};
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
	mockRegisteredStore = {};
	mockContext = {
		eventPageId: 1,
		eventVersion: 1,
		restBase: '/wp-json/pixfete/v1',
		dateStart: '',
		interval: 5,
		honeypotField: 'website',
		nonce: 'test-nonce',
		i18n: {
			passwordIncorrect: 'The password is incorrect.',
			networkError: 'A network error occurred.',
			initFailed: 'Could not initialize. Please try again.',
			initConnectionFailed: 'Could not initialize. Please check your connection and try again.',
		},
	};
	jest.resetModules();
});

/**
 * Load the slideshow view module and return the registered store.
 *
 * @return {Object} The registered interactivity store definition.
 */
function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

describe('store namespace', () => {
	test('registers under pixfete/slideshow', () => {
		const mockStore = jest.fn((_name, definition) => {
			if (definition) {
				mockRegisteredStore = definition;
			}
			return mockRegisteredStore;
		});

		jest.doMock(
			'@wordpress/interactivity',
			() => ({
				store: mockStore,
				getContext: () => mockContext,
			}),
			{ virtual: true }
		);

		require('../view');

		expect(mockStore).toHaveBeenCalledWith('pixfete/slideshow', expect.any(Object));
	});
});

describe('view getters', () => {
	test('isLoadingView returns true when currentView is loading', () => {
		const store = loadStore();
		expect(store.state.isLoadingView).toBe(true);
	});

	test('isLoadingView returns false when currentView is not loading', () => {
		const store = loadStore();
		store.state.currentView = 'password';
		expect(store.state.isLoadingView).toBe(false);
	});

	test('isPasswordView returns true when currentView is password', () => {
		const store = loadStore();
		store.state.currentView = 'password';
		expect(store.state.isPasswordView).toBe(true);
	});

	test('isPasswordView returns false when currentView is not password', () => {
		const store = loadStore();
		expect(store.state.isPasswordView).toBe(false);
	});

	test('isSlideshowView returns true when currentView is slideshow', () => {
		const store = loadStore();
		store.state.currentView = 'slideshow';
		expect(store.state.isSlideshowView).toBe(true);
	});

	test('isSlideshowView returns false when currentView is not slideshow', () => {
		const store = loadStore();
		expect(store.state.isSlideshowView).toBe(false);
	});

	test('isNotStartedView returns true when currentView is not-started', () => {
		const store = loadStore();
		store.state.currentView = 'not-started';
		expect(store.state.isNotStartedView).toBe(true);
	});

	test('isNotStartedView returns false when currentView is something else', () => {
		const store = loadStore();
		store.state.currentView = 'slideshow';
		expect(store.state.isNotStartedView).toBe(false);
	});
});

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

describe('photo getters', () => {
	test('currentPhotoFull returns full URL when photo exists', () => {
		const store = loadStore();
		store.state.currentPhoto = {
			full: 'https://example.com/photo.jpg',
			guest_name: 'Alice',
			table_name: 'Table 1',
		};
		expect(store.state.currentPhotoFull).toBe('https://example.com/photo.jpg');
	});

	test('currentPhotoFull returns empty string when no photo', () => {
		const store = loadStore();
		store.state.currentPhoto = null;
		expect(store.state.currentPhotoFull).toBe('');
	});

	test('currentPhotoGuestName returns guest name when photo exists', () => {
		const store = loadStore();
		store.state.currentPhoto = {
			full: 'https://example.com/photo.jpg',
			guest_name: 'Alice',
			table_name: 'Table 1',
		};
		expect(store.state.currentPhotoGuestName).toBe('Alice');
	});

	test('currentPhotoGuestName returns empty string when no photo', () => {
		const store = loadStore();
		store.state.currentPhoto = null;
		expect(store.state.currentPhotoGuestName).toBe('');
	});

	test('currentPhotoTableName returns table name when photo exists', () => {
		const store = loadStore();
		store.state.currentPhoto = {
			full: 'https://example.com/photo.jpg',
			guest_name: 'Alice',
			table_name: 'Table 1',
		};
		expect(store.state.currentPhotoTableName).toBe('Table 1');
	});

	test('currentPhotoTableName returns empty string when no photo', () => {
		const store = loadStore();
		store.state.currentPhoto = null;
		expect(store.state.currentPhotoTableName).toBe('');
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
		delete global.document.cookie;
		Object.defineProperty(global.document, 'cookie', {
			value: '',
			writable: true,
			configurable: true,
		});
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
});

describe('init() with past event and no cookie', () => {
	beforeEach(() => {
		delete global.document.cookie;
		Object.defineProperty(global.document, 'cookie', {
			value: '',
			writable: true,
			configurable: true,
		});
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

	test('sets currentView to password when no cookie exists', async () => {
		mockContext.dateStart = '2020-01-01';
		const store = loadStore();
		await runGenerator(store.actions.init());
		expect(store.state.currentView).toBe('password');
	});

	test('sets currentView to password when dateStart is empty', async () => {
		const store = loadStore();
		await runGenerator(store.actions.init());
		expect(store.state.currentView).toBe('password');
	});

	test('populates ctx.nonce from the /token endpoint before showing password view', async () => {
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
});

describe('updatePasswordInput', () => {
	test('updates state.passwordInput from event target value', () => {
		const store = loadStore();
		const mockEvent = { target: { value: 'my-secret-password' } };
		store.actions.updatePasswordInput(mockEvent);
		expect(store.state.passwordInput).toBe('my-secret-password');
	});

	test('updates to empty string when input is cleared', () => {
		const store = loadStore();
		store.state.passwordInput = 'old-value';
		const mockEvent = { target: { value: '' } };
		store.actions.updatePasswordInput(mockEvent);
		expect(store.state.passwordInput).toBe('');
	});
});
