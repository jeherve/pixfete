/**
 * Editor component for the Event Slideshow block.
 *
 * Provides inspector controls for selecting the parent event page (whose
 * photos will be displayed) and configuring the transition interval. When
 * an event page is selected, its password, version, and date range are
 * synced into this block's attributes so render.php has them at render
 * time without querying the parent page.
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ComboboxControl, RangeControl, Button, Placeholder, Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Fetches the event-album block attributes from a given page via the WP REST API.
 *
 * Parses the page's content blocks looking for the event-album block, then
 * returns its attributes (password, eventVersion, dateRangeStart, dateRangeEnd).
 * Returns null if the page doesn't contain an event-album block.
 *
 * @param {number} pageId The page ID to fetch and parse.
 * @return {Promise<Object|null>} The event-album block attributes, or null.
 */
async function fetchEventAttributes(pageId) {
	const page = await apiFetch({
		path: `/wp/v2/pages/${pageId}?context=edit`,
	});

	const content = page?.content?.raw || '';

	const { parse } = await import('@wordpress/block-serialization-default-parser');
	const blocks = parse(content);

	/**
	 * Recursively search a block tree for the event-album block.
	 *
	 * Matches the PHP-side `has_slideshow_for_event` pattern so that the
	 * event-album block is found regardless of nesting depth (e.g. inside
	 * Group, Columns, or other container blocks).
	 *
	 * @param {Array} searchBlocks Parsed blocks to search.
	 * @return {Object|null} The event-album block's attributes, or null.
	 */
	function findBlockAttrs(searchBlocks) {
		for (const block of searchBlocks) {
			if (block.blockName === 'event-guest-photos-sharing/event-album') {
				return block.attrs;
			}
			if (block.innerBlocks) {
				const found = findBlockAttrs(block.innerBlocks);
				if (found) {
					return found;
				}
			}
		}
		return null;
	}

	return findBlockAttrs(blocks);
}

/**
 * Edit component for the event-slideshow block.
 *
 * @param {Object}   props               Block edit props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {import('react').ReactElement} The editor UI.
 */
export default function Edit({ attributes, setAttributes }) {
	const { eventPageId, interval, password } = attributes;
	const [isSyncing, setIsSyncing] = useState(false);
	const [syncError, setSyncError] = useState('');

	const blockProps = useBlockProps();

	const pages = useSelect((select) => {
		const allPages = select('core').getEntityRecords('postType', 'page', {
			per_page: 100,
			status: 'publish',
		});
		return (allPages || []).map((page) => ({
			value: page.id,
			label: page.title.rendered || __('(no title)', 'event-guest-photos-sharing'),
		}));
	}, []);

	const syncFromEvent = useCallback(
		async (pageId) => {
			if (!pageId) {
				return;
			}
			setIsSyncing(true);
			setSyncError('');
			try {
				const attrs = await fetchEventAttributes(pageId);
				if (!attrs) {
					setSyncError(__('No Event Photo Album block found on that page.', 'event-guest-photos-sharing'));
					return;
				}
				setAttributes({
					eventPageId: pageId,
					password: attrs.password || '',
					eventVersion: attrs.eventVersion || 1,
					dateRangeStart: attrs.dateRangeStart || '',
					dateRangeEnd: attrs.dateRangeEnd || '',
				});
			} catch {
				setSyncError(__('Could not fetch event page data.', 'event-guest-photos-sharing'));
			} finally {
				setIsSyncing(false);
			}
		},
		[setAttributes]
	);

	const handlePageChange = useCallback(
		(value) => {
			const pageId = Number(value);
			if (pageId > 0) {
				syncFromEvent(pageId);
			}
		},
		[syncFromEvent]
	);

	return (
		<div {...blockProps}>
			<InspectorControls>
				<PanelBody title={__('Event Page', 'event-guest-photos-sharing')}>
					<ComboboxControl
						label={__('Select Event Page', 'event-guest-photos-sharing')}
						value={eventPageId || ''}
						options={pages}
						onChange={handlePageChange}
						help={__(
							'Choose the page containing the Event Photo Album block.',
							'event-guest-photos-sharing'
						)}
					/>
					{eventPageId > 0 && (
						<Button
							variant="secondary"
							isBusy={isSyncing}
							disabled={isSyncing}
							onClick={() => syncFromEvent(eventPageId)}
						>
							{__('Refresh from event', 'event-guest-photos-sharing')}
						</Button>
					)}
					{syncError && (
						<Notice status="error" isDismissible={false}>
							{syncError}
						</Notice>
					)}
				</PanelBody>
				<PanelBody title={__('Slideshow Settings', 'event-guest-photos-sharing')} initialOpen={true}>
					<RangeControl
						label={__('Seconds per photo', 'event-guest-photos-sharing')}
						value={interval}
						onChange={(value) => setAttributes({ interval: value })}
						min={2}
						max={30}
						help={__(
							'How long each photo is displayed before transitioning to the next.',
							'event-guest-photos-sharing'
						)}
					/>
				</PanelBody>
			</InspectorControls>

			<Placeholder
				icon="slides"
				label={__('Event Slideshow', 'event-guest-photos-sharing')}
				instructions={
					eventPageId > 0 && password
						? __(
								'Slideshow is configured. Photos will appear here during the event.',
								'event-guest-photos-sharing'
							)
						: __('Select an event page in the block settings to get started.', 'event-guest-photos-sharing')
				}
			/>
		</div>
	);
}
