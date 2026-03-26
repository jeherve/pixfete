import { buildUrl } from '../build-url';

describe('buildUrl', () => {
	const basePage = {
		permalink: 'https://example.com/wedding/',
		password: 'secret123',
	};

	test('returns permalink when no options selected', () => {
		expect(buildUrl(basePage, { includePassword: false, includeTable: false, tableName: '' })).toBe(
			'https://example.com/wedding/'
		);
	});

	test('appends key param when includePassword is true', () => {
		expect(buildUrl(basePage, { includePassword: true, includeTable: false, tableName: '' })).toBe(
			'https://example.com/wedding/?key=secret123'
		);
	});

	test('appends table param when includeTable is true', () => {
		expect(buildUrl(basePage, { includePassword: false, includeTable: true, tableName: 'Table 5' })).toBe(
			'https://example.com/wedding/?table=Table%205'
		);
	});

	test('appends both params when both selected', () => {
		expect(buildUrl(basePage, { includePassword: true, includeTable: true, tableName: 'Table 5' })).toBe(
			'https://example.com/wedding/?key=secret123&table=Table%205'
		);
	});

	test('ignores table when tableName is empty', () => {
		expect(buildUrl(basePage, { includePassword: true, includeTable: true, tableName: '' })).toBe(
			'https://example.com/wedding/?key=secret123'
		);
	});

	describe('plain permalink structure (query string in permalink)', () => {
		const plainPage = {
			permalink: 'https://example.com/?page_id=6',
			password: 'secret123',
		};

		test('uses & separator when permalink already contains ?', () => {
			expect(buildUrl(plainPage, { includePassword: true, includeTable: false, tableName: '' })).toBe(
				'https://example.com/?page_id=6&key=secret123'
			);
		});

		test('appends both params with & when permalink has query string', () => {
			expect(buildUrl(plainPage, { includePassword: true, includeTable: true, tableName: 'Table 5' })).toBe(
				'https://example.com/?page_id=6&key=secret123&table=Table%205'
			);
		});

		test('returns plain permalink unchanged when no options selected', () => {
			expect(buildUrl(plainPage, { includePassword: false, includeTable: false, tableName: '' })).toBe(
				'https://example.com/?page_id=6'
			);
		});
	});
});
