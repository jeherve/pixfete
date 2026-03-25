import { render, screen } from '@testing-library/react';
import { ArchiveStatus } from '../ArchiveStatus';

// Mock @wordpress/i18n.
jest.mock('@wordpress/i18n', () => ({
	__: (str) => str,
}));

// Mock @wordpress/components.
jest.mock('@wordpress/components', () => ({
	Spinner: () => <span data-testid="spinner" />,
	Button: ({ children, href, ...props }) => (
		<a href={href} {...props}>
			{children}
		</a>
	),
}));

describe('ArchiveStatus', () => {
	test('shows message when event has not ended', () => {
		render(<ArchiveStatus archive={null} dateRangeEnd="2099-12-31" />);
		expect(screen.getByText('The photo archive will be available once the event ends.')).toBeInTheDocument();
	});

	test('shows message when no dateRangeEnd is set', () => {
		render(<ArchiveStatus archive={null} dateRangeEnd="" />);
		expect(screen.getByText('The photo archive will be available once the event ends.')).toBeInTheDocument();
	});

	test('shows pending message', () => {
		render(<ArchiveStatus archive={{ status: 'pending' }} dateRangeEnd="2020-01-01" />);
		expect(screen.getByText('Photo archive is queued for generation.')).toBeInTheDocument();
	});

	test('shows generating message with spinner', () => {
		render(<ArchiveStatus archive={{ status: 'generating' }} dateRangeEnd="2020-01-01" />);
		expect(screen.getByText('Photo archive is being generated…')).toBeInTheDocument();
		expect(screen.getByTestId('spinner')).toBeInTheDocument();
	});

	test('shows download button when complete', () => {
		render(
			<ArchiveStatus
				archive={{
					status: 'complete',
					url: 'https://example.com/archive.zip',
				}}
				dateRangeEnd="2020-01-01"
			/>
		);
		expect(screen.getByText('Photo archive ready.')).toBeInTheDocument();
		const link = screen.getByRole('link', { name: 'Download ZIP' });
		expect(link).toHaveAttribute('href', 'https://example.com/archive.zip');
		expect(link).toHaveAttribute('download');
	});

	test('shows failed message', () => {
		render(<ArchiveStatus archive={{ status: 'failed' }} dateRangeEnd="2020-01-01" />);
		expect(screen.getByText('Archive generation failed.')).toBeInTheDocument();
	});
});
