import { buildFilename } from '../build-filename';

describe('buildFilename', () => {
	test('generates filename from slug only', () => {
		expect(buildFilename('summer-wedding', '')).toBe('event-qr-summer-wedding');
	});

	test('includes table name when provided', () => {
		expect(buildFilename('summer-wedding', 'Table 5')).toBe('event-qr-summer-wedding-table-5');
	});

	test('lowercases and hyphenates table name', () => {
		expect(buildFilename('party', 'Head Table')).toBe('event-qr-party-head-table');
	});

	test('handles empty slug', () => {
		expect(buildFilename('', 'Table 1')).toBe('event-qr-table-1');
	});
});
