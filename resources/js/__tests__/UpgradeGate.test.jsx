import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { pageProps } = vi.hoisted(() => ({
    pageProps: { current: { plan: null, plan_features: {} } },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps.current }),
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key, options) => (
            key === 'ui.feature_locked_current_plan'
                ? `Current plan: ${options.plan}`
                : key
        ),
    }),
}));

import UpgradeGate from '@/Components/UpgradeGate';

describe('UpgradeGate', () => {
    beforeEach(() => {
        pageProps.current = { plan: null, plan_features: {} };
    });

    it('renders its children when the plan includes the feature', () => {
        pageProps.current = {
            plan: { name: 'Pro' },
            plan_features: { tasks: true },
        };

        render(
            <UpgradeGate feature="tasks">
                <p>Tasks content</p>
            </UpgradeGate>,
        );

        expect(screen.getByText('Tasks content')).toBeInTheDocument();
        expect(screen.queryByText('ui.feature_locked_title')).not.toBeInTheDocument();
    });

    it('renders the upgrade prompt when the feature is locked', () => {
        pageProps.current = {
            plan: { name: 'Starter' },
            plan_features: { tasks: false },
        };

        render(
            <UpgradeGate feature="tasks">
                <p>Tasks content</p>
            </UpgradeGate>,
        );

        expect(screen.getByText('ui.feature_locked_title')).toBeInTheDocument();
        expect(screen.getByText('ui.feature_locked_body')).toBeInTheDocument();
        expect(screen.getByText('Current plan: Starter')).toBeInTheDocument();
        expect(screen.queryByText('Tasks content')).not.toBeInTheDocument();
        expect(screen.getByRole('link')).toHaveAttribute('href', '/client.pricing');
    });

    it('treats a missing flag as locked', () => {
        pageProps.current = { plan: { name: 'Starter' }, plan_features: {} };

        render(<UpgradeGate feature="companies" />);

        expect(screen.getByText('ui.feature_locked_title')).toBeInTheDocument();
    });

    it('accepts custom title and description copy', () => {
        pageProps.current = { plan: null, plan_features: { tasks: false } };

        render(
            <UpgradeGate
                feature="tasks"
                title="Tasks are a Pro feature"
                description="Upgrade to organise your pipeline."
            />,
        );

        expect(screen.getByText('Tasks are a Pro feature')).toBeInTheDocument();
        expect(screen.getByText('Upgrade to organise your pipeline.')).toBeInTheDocument();
    });

    it('hides the current plan line when the plan name is unavailable', () => {
        pageProps.current = { plan: null, plan_features: { tasks: false } };

        render(<UpgradeGate feature="tasks" />);

        expect(screen.queryByText(/Current plan:/)).not.toBeInTheDocument();
    });
});
