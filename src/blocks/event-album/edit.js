/**
 * Block editor component for the Event Photo Album block.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, Button, DatePicker, FormTokenField } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

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
 * Minimum password length enforced in the editor.
 *
 * This must stay in sync with the server-side default for the
 * `pixfete_password_min_length` filter (currently 8).
 *
 * @type {number}
 */
const MIN_PASSWORD_LENGTH = 8;

/**
 * Template for InnerBlocks — a single paragraph with placeholder text.
 */
const INNER_BLOCKS_TEMPLATE = [
	[
		'core/paragraph',
		{
			placeholder: __('Enter the consent message guests will see before uploading photos…', 'pixfete'),
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
	const { password, eventVersion, dateRangeStart, dateRangeEnd, enableTableNames, moderators } = attributes;

	const blockProps = useBlockProps();
	const { lockPostSaving, unlockPostSaving } = useDispatch('core/editor');
	const isTooShort = password.length > 0 && password.length < MIN_PASSWORD_LENGTH;

	// Lock saving when the password is too short so the admin cannot
	// publish or update the post with an invalid password.
	useEffect(() => {
		if (isTooShort) {
			lockPostSaving('egps-password-too-short');
		} else {
			unlockPostSaving('egps-password-too-short');
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [isTooShort]);

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

	// Moderator user search state.
	const [moderatorSuggestions, setModeratorSuggestions] = useState([]);
	const [moderatorTokens, setModeratorTokens] = useState([]);
	const [allModeratorUsers, setAllModeratorUsers] = useState([]);

	// Fetch moderator-capable users and resolve existing assignments on mount.
	useEffect(() => {
		apiFetch({ path: '/wp/v2/users?per_page=100&context=edit' }).then((users) => {
			const eligible = users.filter(
				(user) => user.capabilities?.pixfete_moderate_photos || user.capabilities?.manage_options
			);
			setAllModeratorUsers(eligible);
			setModeratorSuggestions(eligible.map((user) => user.name));

			if (moderators.length > 0) {
				const tokens = moderators
					.map((id) => {
						const user = eligible.find((u) => u.id === id);
						return user ? user.name : null;
					})
					.filter(Boolean);
				setModeratorTokens(tokens);
			}
		});
	}, []); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Handle changes to the moderator token field.
	 *
	 * Converts display-name tokens back to user IDs and persists
	 * them as the `moderators` block attribute so the server knows
	 * which users are allowed to delete photos from this album.
	 *
	 * @param {string[]} tokens Display names selected in the FormTokenField.
	 */
	const onModeratorsChange = (tokens) => {
		setModeratorTokens(tokens);
		const ids = tokens
			.map((name) => {
				const user = allModeratorUsers.find((u) => u.name === name);
				return user ? user.id : null;
			})
			.filter(Boolean);
		setAttributes({ moderators: ids });
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
				<PanelBody title={__('Event Settings', 'pixfete')}>
					<TextControl
						label={__('Event Password', 'pixfete')}
						value={password}
						onChange={(value) => setAttributes({ password: value })}
						help={
							isTooShort
								? sprintf(
										/* translators: %d: minimum number of characters required for the event password */
										__(
											'Password must be at least %d characters. Guests will not be able to access the album until this is fixed.',
											'pixfete'
										),
										MIN_PASSWORD_LENGTH
									)
								: __('Guests will use this password to access the album.', 'pixfete')
						}
						__nextHasNoMarginBottom
					/>
					<Button variant="secondary" onClick={handleRegeneratePassword} style={{ marginTop: '8px' }}>
						{__('Regenerate Password', 'pixfete')}
					</Button>
				</PanelBody>

				<PanelBody title={__('Date Range', 'pixfete')} initialOpen={false}>
					<p className="components-base-control__label">{__('Start Date', 'pixfete')}</p>
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
							{__('Clear start date', 'pixfete')}
						</Button>
					)}

					<p className="components-base-control__label" style={{ marginTop: '16px' }}>
						{__('End Date', 'pixfete')}
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
							{__('Clear end date', 'pixfete')}
						</Button>
					)}
				</PanelBody>

				<PanelBody title={__('Guest Registration', 'pixfete')} initialOpen={false}>
					<ToggleControl
						label={__('Enable table names', 'pixfete')}
						checked={enableTableNames}
						onChange={(value) => setAttributes({ enableTableNames: value })}
						help={__('Ask guests which table they are seated at.', 'pixfete')}
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={__('Moderators', 'pixfete')} initialOpen={false}>
					<p className="egps-editor-help">
						{__(
							'Assign users who can delete photos from the live gallery on their phone. Users must have the Event Photo Moderator role.',
							'pixfete'
						)}
					</p>
					<FormTokenField
						label={__('Moderators', 'pixfete')}
						value={moderatorTokens}
						suggestions={moderatorSuggestions}
						onChange={onModeratorsChange}
						__experimentalExpandOnFocus
					/>
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>
				<div className="egps-editor-consent">
					<p className="egps-editor-label">{__('Consent Message', 'pixfete')}</p>
					<p className="egps-editor-help">
						{__('This message will be shown to guests before they can upload photos.', 'pixfete')}
					</p>
					<InnerBlocks template={INNER_BLOCKS_TEMPLATE} />
				</div>

				<div className="egps-editor-preview-placeholder">
					<p className="egps-editor-label">{__('Guest View Preview', 'pixfete')}</p>
					<div className="egps-editor-preview-buttons">
						<span className="egps-editor-preview-button">{__('Take Photo', 'pixfete')}</span>
						<span className="egps-editor-preview-button">{__('Choose from Library', 'pixfete')}</span>
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
