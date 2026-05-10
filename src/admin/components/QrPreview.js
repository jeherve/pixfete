import { useRef, useEffect, useState, useMemo } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import QRCodeStyling from 'qr-code-styling';
import { buildQrOptions } from '../utils/build-qr-options';
import { buildFilename } from '../utils/build-filename';

export function QrPreview({ url, slug, tableName, config }) {
	const containerRef = useRef(null);
	const qrRef = useRef(null);
	const [isReady, setIsReady] = useState(false);

	const qrOptions = useMemo(
		() =>
			buildQrOptions({
				data: url,
				fgColor: config.fgColor,
				bgColor: config.bgColor,
				cornerStyle: config.cornerStyle,
				logoDataUrl: config.includeLogo ? config.logoDataUrl : null,
			}),
		[url, config.fgColor, config.bgColor, config.cornerStyle, config.includeLogo, config.logoDataUrl]
	);

	useEffect(() => {
		if (!containerRef.current) {
			return;
		}

		if (!qrRef.current) {
			qrRef.current = new QRCodeStyling(qrOptions);
			// Clear existing content safely before appending QR code.
			while (containerRef.current.firstChild) {
				containerRef.current.removeChild(containerRef.current.firstChild);
			}
			qrRef.current.append(containerRef.current);
		} else {
			qrRef.current.update(qrOptions);
		}

		setIsReady(true);
	}, [qrOptions]);

	const handleDownload = () => {
		if (qrRef.current) {
			const filename = buildFilename(slug, tableName);
			qrRef.current.download({ name: filename, extension: 'png' });
		}
	};

	return (
		<div className="pixfete-qr-preview">
			<h2>{__('Preview', 'pixfete')}</h2>
			<div ref={containerRef} className="pixfete-qr-preview-canvas" />
			<p className="pixfete-qr-preview-url">
				<code>{url}</code>
			</p>
			<Button variant="primary" onClick={handleDownload} disabled={!isReady}>
				{__('Download PNG', 'pixfete')}
			</Button>
		</div>
	);
}
