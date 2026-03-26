/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Mock @wordpress/interactivity with a configurable getContext.
let registeredStore = {};
let mockContext = {
	pageId: 1,
	restBase: '/wp-json/event-guest-photos-sharing/v1',
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
		restBase: '/wp-json/event-guest-photos-sharing/v1',
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
