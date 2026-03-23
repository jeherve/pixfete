// cornersDotOptions only supports 'square' and 'dot' in qr-code-styling.
// When cornersSquareOptions is 'extra-rounded', fall back to 'dot' for cornersDot.
function getCornerDotType(cornerStyle) {
	return cornerStyle === 'extra-rounded' ? 'dot' : cornerStyle;
}

export function buildQrOptions({ data, fgColor, bgColor, cornerStyle, logoDataUrl }) {
	const options = {
		width: 300,
		height: 300,
		data,
		dotsOptions: {
			type: 'rounded',
			color: fgColor,
		},
		backgroundOptions: {
			color: bgColor,
		},
		cornersSquareOptions: {
			type: cornerStyle,
		},
		cornersDotOptions: {
			type: getCornerDotType(cornerStyle),
		},
		qrOptions: {
			errorCorrectionLevel: 'Q',
		},
	};

	if (logoDataUrl) {
		options.image = logoDataUrl;
		options.imageOptions = {
			margin: 4,
			imageSize: 0.3,
		};
	}

	return options;
}
