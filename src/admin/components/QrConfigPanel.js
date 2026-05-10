import { CheckboxControl, TextControl, ColorPicker, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const CORNER_STYLES = [
	{ value: 'square', label: __('Square', 'pixfete') },
	{ value: 'dot', label: __('Dot', 'pixfete') },
	{ value: 'extra-rounded', label: __('Extra Rounded', 'pixfete') },
];

export function QrConfigPanel({ page, config, onConfigChange }) {
	const update = (key, value) => onConfigChange({ ...config, [key]: value });

	return (
		<div className="egps-qr-config-panel">
			<h2>{__('QR Code Settings', 'pixfete')}</h2>

			<fieldset>
				<legend>{__('Include in URL', 'pixfete')}</legend>

				{page.password ? (
					<CheckboxControl
						label={__('Password (skips password entry)', 'pixfete')}
						checked={config.includePassword}
						onChange={(v) => update('includePassword', v)}
					/>
				) : (
					<p className="egps-qr-no-password">{__('No password set for this event.', 'pixfete')}</p>
				)}

				{page.enableTableNames && (
					<>
						<CheckboxControl
							label={__('Table name', 'pixfete')}
							checked={config.includeTable}
							onChange={(v) => update('includeTable', v)}
						/>
						{config.includeTable && (
							<TextControl
								label={__('Table name', 'pixfete')}
								value={config.tableName}
								onChange={(v) => update('tableName', v)}
								placeholder={__('e.g. Table 5', 'pixfete')}
							/>
						)}
					</>
				)}
			</fieldset>

			{page.logoDataUrl && (
				<fieldset>
					<legend>{__('Logo', 'pixfete')}</legend>
					<CheckboxControl
						label={__('Include logo', 'pixfete')}
						checked={config.includeLogo}
						onChange={(v) => update('includeLogo', v)}
					/>
				</fieldset>
			)}

			<fieldset>
				<legend>{__('Foreground Color', 'pixfete')}</legend>
				<ColorPicker color={config.fgColor} onChange={(v) => update('fgColor', v)} enableAlpha={false} />
			</fieldset>

			<fieldset>
				<legend>{__('Background Color', 'pixfete')}</legend>
				<ColorPicker color={config.bgColor} onChange={(v) => update('bgColor', v)} enableAlpha={false} />
			</fieldset>

			<fieldset>
				<legend>{__('Corner Style', 'pixfete')}</legend>
				<div className="egps-qr-corner-styles">
					{CORNER_STYLES.map((style) => (
						<Button
							key={style.value}
							variant={config.cornerStyle === style.value ? 'primary' : 'secondary'}
							onClick={() => update('cornerStyle', style.value)}
						>
							{style.label}
						</Button>
					))}
				</div>
			</fieldset>
		</div>
	);
}
