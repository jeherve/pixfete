/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

let registeredStore = {};
let mockContext = {
	pageId: 1,
	restBase: '/wp-json/pixfete/v1',
	dateStart: '',
	dateEnd: '',
	showPasswordLabel: 'Show password',
	hidePasswordLabel: 'Hide password',
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
		showPasswordLabel: 'Show password',
		hidePasswordLabel: 'Hide password',
	};
	jest.resetModules();
});

function loadStore() {
	require('../view');
	return registeredStore;
}

describe('password visibility toggle', () => {
	test('passwordVisible defaults to false', () => {
		const store = loadStore();
		expect(store.state.passwordVisible).toBe(false);
	});

	test('passwordInputType is "password" when hidden', () => {
		const store = loadStore();
		expect(store.state.passwordInputType).toBe('password');
	});

	test('passwordInputType becomes "text" when revealed', () => {
		const store = loadStore();
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
