/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

import { render, screen, act } from '@testing-library/react';

// ── Mocks ────────────────────────────────────────────────────────────

const mockLockPostSaving = jest.fn();
const mockUnlockPostSaving = jest.fn();

// @wordpress/data — provides useDispatch and useSelect.
jest.mock(
	'@wordpress/data',
	() => ({
		useDispatch: (store) => {
			if (store === 'core/editor') {
				return {
					lockPostSaving: mockLockPostSaving,
					unlockPostSaving: mockUnlockPostSaving,
				};
			}
			return {};
		},
		useSelect: () => ({}),
	}),
	{ virtual: true }
);

// @wordpress/block-editor — virtual because it is a webpack external.
jest.mock(
	'@wordpress/block-editor',
	() => ({
		useBlockProps: () => ({}),
		InspectorControls: ({ children }) => children,
		InnerBlocks: () => null,
	}),
	{ virtual: true }
);

// @wordpress/components — forward-render so we can inspect props.
jest.mock('@wordpress/components', () => ({
	PanelBody: ({ children }) => <div>{children}</div>,
	TextControl: ({ label, value, help, ...rest }) => (
		<div>
			{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
			<label>{label}</label>
			<input value={value} readOnly data-testid="password-input" />
			{help && <p data-testid="password-help">{help}</p>}
			{rest.children}
		</div>
	),
	ToggleControl: () => null,
	Button: ({ children, ...rest }) => <button {...rest}>{children}</button>,
	DatePicker: () => null,
	FormTokenField: () => null,
}));

// @wordpress/api-fetch — virtual because it is a webpack external.
// Returns a resolved Promise with an empty array so the moderator-fetch
// useEffect can safely call .then() without throwing, and React state
// updates triggered inside the Promise callback are handled by act().
jest.mock('@wordpress/api-fetch', () => jest.fn(() => Promise.resolve([])), { virtual: true });

// @wordpress/element — re-export React hooks so useEffect works.
jest.mock(
	'@wordpress/element',
	() => ({
		// eslint-disable-next-line import/no-extraneous-dependencies
		useEffect: require('react').useEffect,
		// eslint-disable-next-line import/no-extraneous-dependencies
		useState: require('react').useState,
	}),
	{ virtual: true }
);

// Stub crypto.getRandomValues (used by generatePassword).
Object.defineProperty(global, 'crypto', {
	value: {
		getRandomValues: (arr) => {
			for (let i = 0; i < arr.length; i++) {
				arr[i] = 65 + i; // Deterministic: produces 'ABCDEFGHIJKL'
			}
			return arr;
		},
	},
});

import Edit from '../edit';

// ── Tests ────────────────────────────────────────────────────────────

describe('Edit — password validation', () => {
	beforeEach(() => {
		mockLockPostSaving.mockClear();
		mockUnlockPostSaving.mockClear();
	});

	test('locks post saving and shows error when password is too short', async () => {
		await act(async () => {
			render(
				<Edit
					attributes={{
						password: 'short',
						eventVersion: 1,
						dateRangeStart: '',
						dateRangeEnd: '',
						enableTableNames: false,
						moderators: [],
					}}
					setAttributes={jest.fn()}
				/>
			);
		});

		expect(mockLockPostSaving).toHaveBeenCalledWith('egps-password-too-short');
		expect(mockUnlockPostSaving).not.toHaveBeenCalled();
		expect(screen.getByText(/at least 8 characters/i)).toBeInTheDocument();
	});

	test('unlocks post saving when password meets minimum length', async () => {
		await act(async () => {
			render(
				<Edit
					attributes={{
						password: 'validpassword',
						eventVersion: 1,
						dateRangeStart: '',
						dateRangeEnd: '',
						enableTableNames: false,
						moderators: [],
					}}
					setAttributes={jest.fn()}
				/>
			);
		});

		expect(mockUnlockPostSaving).toHaveBeenCalledWith('egps-password-too-short');
		expect(mockLockPostSaving).not.toHaveBeenCalled();
		expect(screen.queryByText(/at least 8 characters/i)).not.toBeInTheDocument();
	});

	test('does not show error or lock saving when password is empty', async () => {
		// Empty password means the block was just inserted and the
		// useEffect auto-generator hasn't fired yet. We should not
		// flash a validation error during this transient state.
		const setAttributes = jest.fn();
		await act(async () => {
			render(
				<Edit
					attributes={{
						password: '',
						eventVersion: 1,
						dateRangeStart: '',
						dateRangeEnd: '',
						enableTableNames: false,
						moderators: [],
					}}
					setAttributes={setAttributes}
				/>
			);
		});

		expect(mockLockPostSaving).not.toHaveBeenCalled();
		// unlockPostSaving is called because isTooShort is false — this is a
		// harmless no-op when no lock was ever set.
		expect(mockUnlockPostSaving).toHaveBeenCalledWith('egps-password-too-short');
		expect(screen.queryByText(/at least 8 characters/i)).not.toBeInTheDocument();
	});

	test('locks saving for password of exactly 7 characters', async () => {
		await act(async () => {
			render(
				<Edit
					attributes={{
						password: 'evelyne',
						eventVersion: 1,
						dateRangeStart: '',
						dateRangeEnd: '',
						enableTableNames: false,
						moderators: [],
					}}
					setAttributes={jest.fn()}
				/>
			);
		});

		expect(mockLockPostSaving).toHaveBeenCalledWith('egps-password-too-short');
		expect(screen.getByText(/at least 8 characters/i)).toBeInTheDocument();
	});

	test('unlocks saving for password of exactly 8 characters', async () => {
		await act(async () => {
			render(
				<Edit
					attributes={{
						password: 'exactly8',
						eventVersion: 1,
						dateRangeStart: '',
						dateRangeEnd: '',
						enableTableNames: false,
						moderators: [],
					}}
					setAttributes={jest.fn()}
				/>
			);
		});

		expect(mockUnlockPostSaving).toHaveBeenCalledWith('egps-password-too-short');
		expect(screen.queryByText(/at least 8 characters/i)).not.toBeInTheDocument();
	});
});
