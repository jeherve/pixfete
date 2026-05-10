/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock @wordpress/interactivity with a configurable getContext.
let registeredStore = {};
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
			registeredStore = definition;
			return definition;
		},
		getContext: () => mockContext,
	}),
	{ virtual: true }
);

beforeEach(() => {
	registeredStore = {};
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
	return registeredStore;
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

describe('init() with future event', () => {
	beforeEach(() => {
		// Stub browser APIs that init() uses.
		delete global.document.cookie;
		Object.defineProperty(global.document, 'cookie', {
			value: '',
			writable: true,
			configurable: true,
		});
		// Stub window.location.href for URL parsing.
		delete window.location;
		window.location = new URL('https://example.com/event-page/');
		// Stub window.history.replaceState for cleanUrlParams.
		window.history.replaceState = jest.fn();
	});

	test('sets currentView to not-started when event is in the future', () => {
		mockContext.dateStart = '2099-12-31';
		const store = loadStore();
		store.actions.init();
		expect(store.state.currentView).toBe('not-started');
	});

	test('does not set not-started when event has started', () => {
		mockContext.dateStart = '2020-01-01';
		const store = loadStore();
		store.actions.init();
		// Should proceed to password view (no cookie, no key param).
		expect(store.state.currentView).toBe('password');
	});

	test('does not set not-started when no dateStart is set', () => {
		const store = loadStore();
		store.actions.init();
		expect(store.state.currentView).toBe('password');
	});
});
