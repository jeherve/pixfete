import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function PageSelector({ pages, selectedPageId, onChange }) {
	if (!pages.length) {
		return null;
	}

	const options = pages.map((page) => ({
		label: page.title,
		value: String(page.id),
	}));

	return (
		<div className="egps-qr-page-selector">
			<SelectControl
				label={__('Select Event Page', 'pixfete')}
				value={String(selectedPageId)}
				options={options}
				onChange={(value) => onChange(Number(value))}
			/>
		</div>
	);
}
