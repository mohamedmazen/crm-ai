import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import OnboardingChecklist from '@/Components/OnboardingChecklist';
import ErrorState from '@/Components/ui/ErrorState';
import Spinner from '@/Components/ui/Spinner';
import { TableSkeleton, ListSkeleton, CardSkeleton } from '@/Components/ui/Skeleton';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key, options) => options?.defaultValue ?? key,
    }),
}));

const steps = [
    { key: 'channel', title: 'Connect a channel', description: 'Pick a channel', href: '#' },
    { key: 'contacts', title: 'Import contacts', description: 'Upload a CSV', done: true },
    { key: 'automation', title: 'Launch automation', description: 'Follow up automatically' },
];

describe('OnboardingChecklist', () => {
    it('derives completion from the steps', () => {
        render(<OnboardingChecklist steps={steps} />);

        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '33');
    });

    it('marks completed steps and links pending ones', () => {
        render(<OnboardingChecklist steps={steps} />);

        expect(screen.getByRole('link', { name: /connect a channel/i })).toHaveAttribute('href', '#');
        expect(screen.getByText('Import contacts')).toHaveClass('line-through');
    });

    it('toggles sample data', () => {
        const onToggleSample = vi.fn();
        render(
            <OnboardingChecklist
                steps={steps}
                sampleEnabled={false}
                onToggleSample={onToggleSample}
            />,
        );

        const toggle = screen.getByRole('switch');
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        toggle.click();
        expect(onToggleSample).toHaveBeenCalledWith(true);
    });

    it('can be dismissed', () => {
        const onDismiss = vi.fn();
        render(<OnboardingChecklist steps={steps} onDismiss={onDismiss} />);

        screen.getByRole('button', { name: 'Dismiss checklist' }).click();
        expect(onDismiss).toHaveBeenCalledOnce();
    });

    it('celebrates when every step is done', () => {
        const done = steps.map((step) => ({ ...step, done: true }));
        render(<OnboardingChecklist steps={done} />);

        expect(screen.getByText('You are all set 🎉')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    });
});

describe('ErrorState', () => {
    it('announces the failure and offers retry', () => {
        const onRetry = vi.fn();
        render(<ErrorState description="The request failed." onRetry={onRetry} />);

        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(screen.getByText('The request failed.')).toBeInTheDocument();

        screen.getByRole('button', { name: 'Try again' }).click();
        expect(onRetry).toHaveBeenCalledOnce();
    });
});

describe('Spinner', () => {
    it('is announced as a status update', () => {
        render(<Spinner />);

        expect(screen.getByRole('status')).toHaveTextContent('Loading…');
    });
});

describe('skeletons', () => {
    it('renders table, list and card placeholders', () => {
        const { rerender } = render(<TableSkeleton rows={3} columns={4} />);
        expect(screen.getByTestId('table-skeleton')).toBeInTheDocument();

        rerender(<ListSkeleton rows={2} />);
        expect(screen.getByTestId('list-skeleton')).toBeInTheDocument();

        const { container } = render(<CardSkeleton />);
        expect(container.querySelectorAll('.animate-pulse').length).toBeGreaterThan(0);
    });
});
