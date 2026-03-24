/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

// Provide module stubs for @wordpress packages that are only available at
// runtime inside WordPress. Jest cannot mock modules that don't exist on disk,
// so we register them manually before importing the module under test.
jest.mock(
	'@wordpress/block-editor',
	() => ({
		useBlockProps: {
			save: () => ({ className: 'wp-block-egps-event-album' }),
		},
		InnerBlocks: {
			Content: function InnerBlocksContent() {
				return 'InnerBlocks.Content';
			},
		},
	}),
	{ virtual: true }
);

import save from '../save';

describe('Event Album block save', () => {
	test('save function returns a non-null element containing InnerBlocks.Content', () => {
		// The save function must return a JSX element that includes
		// InnerBlocks.Content so WordPress serializes inner block content
		// (the consent message) into the post HTML. Without this, the
		// consent message is lost on save/refresh.
		const result = save();
		expect(result).not.toBeNull();
		expect(result).toBeDefined();
	});

	test('save output includes InnerBlocks.Content in its children', () => {
		const result = save();
		// The rendered element should have InnerBlocks.Content as a child.
		const { children } = result.props;
		const childArray = Array.isArray(children) ? children : [children];
		const hasInnerBlocksContent = childArray.some(
			(child) => child && child.type && child.type.name === 'InnerBlocksContent'
		);
		expect(hasInnerBlocksContent).toBe(true);
	});
});
