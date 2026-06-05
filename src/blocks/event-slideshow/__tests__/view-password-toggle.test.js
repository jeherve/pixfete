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

/**
 * Drive a generator-based action to completion, awaiting any yielded promises.
 *
 * @param {Object} gen The generator returned by an action.
 * @return {Promise} Resolves with the generator's return value.
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

describe('slideshow submitPassword empty input', () => {
	afterEach(() => {
		delete global.fetch;
	});

	test('shows the required message and skips the server when empty', async () => {
		mockContext.i18n.passwordRequired = 'Please enter the event password.';
		global.fetch = jest.fn();

		const store = loadStore();
		store.state.passwordInput = '   ';

		await runGenerator(store.actions.submitPassword({ preventDefault: () => {} }));

		expect(store.state.errorMessage).toBe('Please enter the event password.');
		expect(global.fetch).not.toHaveBeenCalled();
		// Should never enter the submitting state for an empty submission.
		expect(store.state.isSubmitting).toBe(false);
	});
});
