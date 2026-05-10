import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { PageSelector } from './PageSelector';
import { QrConfigPanel } from './QrConfigPanel';
import { QrPreview } from './QrPreview';
import { ArchiveStatus } from './ArchiveStatus';
import { EventCleanup } from './EventCleanup';
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
	/* global pixfeteQrAdmin */
	const { pages } = pixfeteQrAdmin;

	const [selectedPageId, setSelectedPageId] = useState(pages.length ? pages[0].id : null);
	const [config, setConfig] = useState(DEFAULT_CONFIG);

	const selectedPage = useMemo(() => pages.find((p) => p.id === selectedPageId), [pages, selectedPageId]);

	const handlePageChange = (pageId) => {
		setSelectedPageId(pageId);
		setConfig({ ...DEFAULT_CONFIG });
	};

	const handleEventDeleted = (deletedPageId) => {
		// Remove the deleted page from the pages array and select the next available page.
		const remainingPages = pages.filter((p) => p.id !== deletedPageId);
		// Mutate the original array so the selector updates.
		// (pages comes from pixfeteQrAdmin which is a global — we replace it in place.)
		pixfeteQrAdmin.pages = remainingPages;
		setSelectedPageId(remainingPages.length ? remainingPages[0].id : null);
	};

	if (!pages.length) {
		return (
			<div className="pixfete-qr-empty">
				<p>{__('No pages with the Event Photo Album block were found.', 'pixfete')}</p>
				<p>
					<a href="post-new.php?post_type=page">{__('Create a new page', 'pixfete')}</a>
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
		<div className="pixfete-qr-admin">
			<PageSelector pages={pages} selectedPageId={selectedPageId} onChange={handlePageChange} />

			<h2>{__('QR Code Generator', 'pixfete')}</h2>
			<div className="pixfete-qr-admin-columns">
				<QrConfigPanel page={selectedPage} config={configWithLogo} onConfigChange={setConfig} />
				<QrPreview
					url={url}
					slug={selectedPage.slug}
					tableName={config.includeTable ? config.tableName : ''}
					config={configWithLogo}
				/>
			</div>

			<h2>{__('Photo Archive', 'pixfete')}</h2>
			<div className="pixfete-archive-section">
				<ArchiveStatus archive={selectedPage.archive} dateRangeEnd={selectedPage.dateRangeEnd || ''} />
			</div>

			<h2>{__('Event Cleanup', 'pixfete')}</h2>
			<div className="pixfete-cleanup-section">
				<EventCleanup
					pageId={selectedPage.id}
					dateRangeEnd={selectedPage.dateRangeEnd || ''}
					onEventDeleted={handleEventDeleted}
				/>
			</div>
		</div>
	);
}
