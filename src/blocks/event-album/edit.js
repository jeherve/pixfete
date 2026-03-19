/**
 * Block editor component for the Event Photo Album block.
 *
 * @package
 */

import './style.scss';

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, Button, DatePicker } from '@wordpress/components';
import { useEffect } from '@wordpress/element';

/**
 * Generate a random alphanumeric password of a given length.
 *
 * Uses crypto.getRandomValues() for cryptographic randomness.
 *
 * @param {number} length Password length.
 * @return {string} Random password string.
 */
function generatePassword(length = 12) {
	const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	const values = crypto.getRandomValues(new Uint32Array(length));
	return Array.from(values, (v) => charset[v % charset.length]).join('');
}

/**
 * Template for InnerBlocks — a single paragraph with placeholder text.
 */
const INNER_BLOCKS_TEMPLATE = [
	[
		'core/paragraph',
		{
			placeholder: __(
				'Enter the consent message guests will see before uploading photos…',
				'event-guest-photos-sharing'
			),
		},
	],
];

/**
 * Edit component for the event-album block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Setter for block attributes.
 * @return {import('react').JSX.Element} Editor markup.
 */
export default function Edit({ attributes, setAttributes }) {
	const { password, eventVersion, dateRangeStart, dateRangeEnd, enableTableNames } = attributes;

	const blockProps = useBlockProps();

	// Auto-generate password on first insertion.
	useEffect(() => {
		if (!password) {
			setAttributes({ password: generatePassword() });
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	/**
	 * Regenerate the event password and bump the event version.
	 */
	const handleRegeneratePassword = () => {
		setAttributes({
			password: generatePassword(),
			eventVersion: (eventVersion || 1) + 1,
		});
	};

	/**
	 * Convert a Date object to a YYYY-MM-DD string.
	 *
	 * @param {string|null} dateString ISO date string from DatePicker.
	 * @return {string} Formatted date string or empty string.
	 */
	const toDateString = (dateString) => {
		if (!dateString) {
			return '';
		}
		return dateString.substring(0, 10);
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={__('Event Settings', 'event-guest-photos-sharing')}>
					<TextControl
						label={__('Event Password', 'event-guest-photos-sharing')}
						value={password}
						onChange={(value) => setAttributes({ password: value })}
						help={__('Guests will use this password to access the album.', 'event-guest-photos-sharing')}
						__nextHasNoMarginBottom
					/>
					<Button variant="secondary" onClick={handleRegeneratePassword} style={{ marginTop: '8px' }}>
						{__('Regenerate Password', 'event-guest-photos-sharing')}
					</Button>
				</PanelBody>

				<PanelBody title={__('Date Range', 'event-guest-photos-sharing')} initialOpen={false}>
					<p className="components-base-control__label">{__('Start Date', 'event-guest-photos-sharing')}</p>
					<DatePicker
						currentDate={dateRangeStart || undefined}
						onChange={(date) =>
							setAttributes({
								dateRangeStart: toDateString(date),
							})
						}
					/>
					{dateRangeStart && (
						<Button
							variant="link"
							isDestructive
							onClick={() => setAttributes({ dateRangeStart: '' })}
							style={{ marginBottom: '16px' }}
						>
							{__('Clear start date', 'event-guest-photos-sharing')}
						</Button>
					)}

					<p className="components-base-control__label" style={{ marginTop: '16px' }}>
						{__('End Date', 'event-guest-photos-sharing')}
					</p>
					<DatePicker
						currentDate={dateRangeEnd || undefined}
						onChange={(date) =>
							setAttributes({
								dateRangeEnd: toDateString(date),
							})
						}
					/>
					{dateRangeEnd && (
						<Button variant="link" isDestructive onClick={() => setAttributes({ dateRangeEnd: '' })}>
							{__('Clear end date', 'event-guest-photos-sharing')}
						</Button>
					)}
				</PanelBody>

				<PanelBody title={__('Guest Registration', 'event-guest-photos-sharing')} initialOpen={false}>
					<ToggleControl
						label={__('Enable table names', 'event-guest-photos-sharing')}
						checked={enableTableNames}
						onChange={(value) => setAttributes({ enableTableNames: value })}
						help={__('Ask guests which table they are seated at.', 'event-guest-photos-sharing')}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>
				<div className="egps-editor-consent">
					<p className="egps-editor-label">{__('Consent Message', 'event-guest-photos-sharing')}</p>
					<p className="egps-editor-help">
						{__(
							'This message will be shown to guests before they can upload photos.',
							'event-guest-photos-sharing'
						)}
					</p>
					<InnerBlocks template={INNER_BLOCKS_TEMPLATE} />
				</div>

				<div className="egps-editor-preview-placeholder">
					<p className="egps-editor-label">{__('Guest View Preview', 'event-guest-photos-sharing')}</p>
					<div className="egps-editor-preview-buttons">
						<span className="egps-editor-preview-button">
							{__('Take Photo', 'event-guest-photos-sharing')}
						</span>
						<span className="egps-editor-preview-button">
							{__('Choose from Library', 'event-guest-photos-sharing')}
						</span>
					</div>
					<div className="egps-editor-preview-grid">
						<div className="egps-editor-preview-cell" />
						<div className="egps-editor-preview-cell" />
						<div className="egps-editor-preview-cell" />
						<div className="egps-editor-preview-cell" />
						<div className="egps-editor-preview-cell" />
						<div className="egps-editor-preview-cell" />
					</div>
				</div>
			</div>
		</>
	);
}
