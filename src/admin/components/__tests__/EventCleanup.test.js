import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import { EventCleanup } from '../EventCleanup';

// Mock @wordpress/i18n.
jest.mock('@wordpress/i18n', () => ({
	__: (str) => str,
}));

// Mock @wordpress/components so tests don't need a full WordPress environment.
jest.mock('@wordpress/components', () => ({
	Button: ({ children, ...props }) => <button {...props}>{children}</button>,
	Notice: ({ children, status }) => <div data-testid={`notice-${status}`}>{children}</div>,
	Spinner: () => <span data-testid="spinner" />,
}));

describe('EventCleanup', () => {
	const defaultProps = {
		pageId: 42,
		dateRangeEnd: '',
		onEventDeleted: jest.fn(),
	};

	beforeAll(() => {
		global.wpApiSettings = { nonce: 'test-nonce', root: '/wp-json/' };
	});

	beforeEach(() => {
		global.fetch = jest.fn();
		global.confirm = jest.fn();
	});

	afterEach(() => {
		jest.clearAllMocks();
	});

	test('renders when dateRangeEnd is empty', () => {
		render(<EventCleanup {...defaultProps} dateRangeEnd="" />);
		expect(screen.getByRole('button', { name: 'Delete Event Data' })).toBeInTheDocument();
	});

	test('renders when dateRangeEnd is in the past', () => {
		render(<EventCleanup {...defaultProps} dateRangeEnd="2020-01-01" />);
		expect(screen.getByRole('button', { name: 'Delete Event Data' })).toBeInTheDocument();
	});

	test('does not render when dateRangeEnd is in the future', () => {
		const { container } = render(<EventCleanup {...defaultProps} dateRangeEnd="2099-12-31" />);
		expect(container).toBeEmptyDOMElement();
	});

	test('does nothing when confirm dialog is cancelled', async () => {
		global.confirm.mockReturnValue(false);

		render(<EventCleanup {...defaultProps} dateRangeEnd="" />);
		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Delete Event Data' }));
		});

		expect(global.fetch).not.toHaveBeenCalled();
	});

	test('calls DELETE endpoint on confirmation', async () => {
		global.confirm.mockReturnValue(true);
		global.fetch.mockResolvedValue({
			ok: true,
			json: () => Promise.resolve({ success: true }),
		});
		const onEventDeleted = jest.fn();

		render(<EventCleanup {...defaultProps} pageId={42} dateRangeEnd="" onEventDeleted={onEventDeleted} />);

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Delete Event Data' }));
		});

		await waitFor(() => {
			expect(global.fetch).toHaveBeenCalledWith('/wp-json/event-guest-photos-sharing/v1/events/42', {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': 'test-nonce' },
			});
			expect(onEventDeleted).toHaveBeenCalledWith(42);
		});
	});

	test('shows error notice on API failure', async () => {
		global.confirm.mockReturnValue(true);
		global.fetch.mockResolvedValue({
			ok: false,
			json: () => Promise.resolve({ message: 'Something went wrong.' }),
		});

		render(<EventCleanup {...defaultProps} dateRangeEnd="" />);

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Delete Event Data' }));
		});

		await waitFor(() => {
			expect(screen.getByTestId('notice-error')).toBeInTheDocument();
			expect(screen.getByText('Something went wrong.')).toBeInTheDocument();
		});
	});
});
