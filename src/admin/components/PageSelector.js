import { SelectControl } from '@wordpress/components';

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
				label="Select Event Page"
				value={String(selectedPageId)}
				options={options}
				onChange={(value) => onChange(Number(value))}
			/>
		</div>
	);
}
