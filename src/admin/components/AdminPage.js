import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { PageSelector } from './PageSelector';
import { QrConfigPanel } from './QrConfigPanel';
import { QrPreview } from './QrPreview';
import { buildUrl } from '../utils/build-url';

const DEFAULT_CONFIG = {
	includePassword: true,
	includeTable: false,
	tableName: '',
	includeLogo: true,
	fgColor: '#1d2327',
	bgColor: '#ffffff',
	cornerStyle: 'square',
	logoDataUrl: null,
};

export function AdminPage() {
	/* global egpsQrAdmin */
	const { pages } = egpsQrAdmin;

	const [selectedPageId, setSelectedPageId] = useState(pages.length ? pages[0].id : null);
	const [config, setConfig] = useState(DEFAULT_CONFIG);

	const selectedPage = useMemo(() => pages.find((p) => p.id === selectedPageId), [pages, selectedPageId]);

	const handlePageChange = (pageId) => {
		setSelectedPageId(pageId);
		setConfig({ ...DEFAULT_CONFIG });
	};

	if (!pages.length) {
		return (
			<div className="egps-qr-empty">
				<p>{__('No pages with the Event Photo Album block were found.', 'event-guest-photos-sharing')}</p>
				<p>
					<a href="post-new.php?post_type=page">{__('Create a new page', 'event-guest-photos-sharing')}</a>
				</p>
			</div>
		);
	}

	if (!selectedPage) {
		return null;
	}

	// configWithLogo merges selectedPage.logoDataUrl into config so QrPreview
	// always has the logo available — even after config state updates from
	// QrConfigPanel which don't include logoDataUrl (it comes from the page,
	// not user input).
	const configWithLogo = {
		...config,
		logoDataUrl: selectedPage.logoDataUrl,
	};

	const url = buildUrl(selectedPage, config);

	return (
		<div className="egps-qr-admin">
			<h2>{__('QR Code Generator', 'event-guest-photos-sharing')}</h2>
			<PageSelector pages={pages} selectedPageId={selectedPageId} onChange={handlePageChange} />
			<div className="egps-qr-admin-columns">
				<QrConfigPanel page={selectedPage} config={configWithLogo} onConfigChange={setConfig} />
				<QrPreview
					url={url}
					slug={selectedPage.slug}
					tableName={config.includeTable ? config.tableName : ''}
					config={configWithLogo}
				/>
			</div>
		</div>
	);
}
