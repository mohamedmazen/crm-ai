import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Lock } from 'lucide-react';
import useFeature from '@/hooks/useFeature';

/**
 * Renders its children when the current plan includes `feature`, otherwise an
 * upgrade prompt. Use it on pages that stay reachable for every plan and only
 * gate part of their content; use the `feature:` route middleware for whole
 * pages and write actions.
 */
export default function UpgradeGate({ feature, title, description, children }) {
    const { t } = useTranslation();
    const { plan } = usePage().props;
    const enabled = useFeature(feature);

    if (enabled) {
        return children ?? null;
    }

    return (
        <div className="rounded-2xl border border-neutral-200 bg-white p-8 text-center shadow-soft dark:border-neutral-800 dark:bg-neutral-900">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                <Lock className="h-5 w-5" />
            </div>
            <h2 className="mt-4 text-lg font-semibold text-neutral-900 dark:text-white">
                {title ?? t('ui.feature_locked_title')}
            </h2>
            <p className="mx-auto mt-2 max-w-md text-sm text-neutral-500 dark:text-neutral-400">
                {description ?? t('ui.feature_locked_body')}
            </p>
            {plan?.name && (
                <p className="mt-3 text-xs font-medium uppercase tracking-wide text-neutral-400">
                    {t('ui.feature_locked_current_plan', { plan: plan.name })}
                </p>
            )}
            <Link
                href={route('client.pricing')}
                className="mt-6 inline-flex items-center justify-center rounded-soft bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-soft transition-colors hover:bg-brand-600"
            >
                {t('ui.view_plans')}
            </Link>
        </div>
    );
}
