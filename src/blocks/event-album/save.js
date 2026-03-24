/**
 * Save component for the Event Photo Album block.
 *
 * Returns InnerBlocks.Content so that WordPress serializes inner block content
 * (the consent message) into the post HTML. Without this, the block is treated
 * as fully dynamic with no inner content, and any text entered in InnerBlocks
 * is discarded on save.
 *
 * @package
 */

import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';

/**
 * Serialize block content for storage.
 *
 * @return {import('react').JSX.Element} Block content wrapper with InnerBlocks.Content.
 */
export default function save() {
	return (
		<div {...useBlockProps.save()}>
			<InnerBlocks.Content />
		</div>
	);
}
