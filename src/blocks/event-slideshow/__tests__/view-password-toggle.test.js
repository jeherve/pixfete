/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

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
		showPasswordLabel: 'Show password',
		hidePasswordLabel: 'Hide password',
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
			showPasswordLabel: 'Show password',
			hidePasswordLabel: 'Hide password',
		},
	};
	jest.resetModules();
});

function loadStore() {
	require('../view');
	return mockRegisteredStore;
}

describe('slideshow password visibility toggle', () => {
	test('passwordVisible defaults to false', () => {
		const store = loadStore();
		expect(store.state.passwordVisible).toBe(false);
	});

	test('passwordInputType reflects passwordVisible', () => {
		const store = loadStore();
		expect(store.state.passwordInputType).toBe('password');
		store.state.passwordVisible = true;
		expect(store.state.passwordInputType).toBe('text');
	});

	test('togglePasswordVisibility flips the flag', () => {
		const store = loadStore();
		store.actions.togglePasswordVisibility();
		expect(store.state.passwordVisible).toBe(true);
		store.actions.togglePasswordVisibility();
		expect(store.state.passwordVisible).toBe(false);
	});

	test('passwordToggleLabel describes the next action', () => {
		const store = loadStore();
		expect(store.state.passwordToggleLabel).toBe('Show password');
		store.state.passwordVisible = true;
		expect(store.state.passwordToggleLabel).toBe('Hide password');
	});
});
