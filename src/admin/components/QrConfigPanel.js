import { CheckboxControl, TextControl, ColorPicker, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const CORNER_STYLES = [
	{ value: 'square', label: __( 'Square', 'event-guest-photos-sharing' ) },
	{ value: 'dot', label: __( 'Dot', 'event-guest-photos-sharing' ) },
	{ value: 'extra-rounded', label: __( 'Extra Rounded', 'event-guest-photos-sharing' ) },
];

export function QrConfigPanel( { page, config, onConfigChange } ) {
	const update = ( key, value ) => onConfigChange( { ...config, [ key ]: value } );

	return (
		<div className="egps-qr-config-panel">
			<h2>{ __( 'QR Code Settings', 'event-guest-photos-sharing' ) }</h2>

			<fieldset>
				<legend>{ __( 'Include in URL', 'event-guest-photos-sharing' ) }</legend>

				{ page.password ? (
					<CheckboxControl
						label={ __( 'Password (skips password entry)', 'event-guest-photos-sharing' ) }
						checked={ config.includePassword }
						onChange={ ( v ) => update( 'includePassword', v ) }
					/>
				) : (
					<p className="egps-qr-no-password">
						{ __( 'No password set for this event.', 'event-guest-photos-sharing' ) }
					</p>
				) }

				{ page.enableTableNames && (
					<>
						<CheckboxControl
							label={ __( 'Table name', 'event-guest-photos-sharing' ) }
							checked={ config.includeTable }
							onChange={ ( v ) => update( 'includeTable', v ) }
						/>
						{ config.includeTable && (
							<TextControl
								label={ __( 'Table name', 'event-guest-photos-sharing' ) }
								value={ config.tableName }
								onChange={ ( v ) => update( 'tableName', v ) }
								placeholder={ __( 'e.g. Table 5', 'event-guest-photos-sharing' ) }
							/>
						) }
					</>
				) }
			</fieldset>

			{ page.logoDataUrl && (
				<fieldset>
					<legend>{ __( 'Logo', 'event-guest-photos-sharing' ) }</legend>
					<CheckboxControl
						label={ __( 'Include logo', 'event-guest-photos-sharing' ) }
						checked={ config.includeLogo }
						onChange={ ( v ) => update( 'includeLogo', v ) }
					/>
				</fieldset>
			) }

			<fieldset>
				<legend>{ __( 'Foreground Color', 'event-guest-photos-sharing' ) }</legend>
				<ColorPicker
					color={ config.fgColor }
					onChange={ ( v ) => update( 'fgColor', v ) }
					enableAlpha={ false }
				/>
			</fieldset>

			<fieldset>
				<legend>{ __( 'Background Color', 'event-guest-photos-sharing' ) }</legend>
				<ColorPicker
					color={ config.bgColor }
					onChange={ ( v ) => update( 'bgColor', v ) }
					enableAlpha={ false }
				/>
			</fieldset>

			<fieldset>
				<legend>{ __( 'Corner Style', 'event-guest-photos-sharing' ) }</legend>
				<div className="egps-qr-corner-styles">
					{ CORNER_STYLES.map( ( style ) => (
						<Button
							key={ style.value }
							variant={ config.cornerStyle === style.value ? 'primary' : 'secondary' }
							onClick={ () => update( 'cornerStyle', style.value ) }
						>
							{ style.label }
						</Button>
					) ) }
				</div>
			</fieldset>
		</div>
	);
}
