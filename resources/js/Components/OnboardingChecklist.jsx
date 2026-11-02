import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Check, Circle, ArrowRight, FlaskConical, X, Rocket } from 'lucide-react';

/**
 * Empty-dashboard onboarding checklist (Phase 4).
 *
 * Shown while a workspace has no meaningful activity yet. Guides the user
 * through the three first-value actions — connect a channel, import contacts,
 * launch the first automation — and can optionally overlay sample data so the
 * dashboard is not a wall of zeros. Sample data is purely client-side and can
 * be switched off at any time (persisted by the parent).
 *
 * Props:
 *   steps         – [{ key, title, description, done, href?, onAction? }]
 *   percent       – 0..100 completion (derived when omitted)
 *   sampleEnabled – whether sample data is currently on
 *   onToggleSample– toggles sample data
 *   onDismiss     – hides the checklist (parent persists the choice)
 *   className     – wrapper classes
 */
export default function OnboardingChecklist({
    steps = [],
    percent,
    sampleEnabled = false,
    onToggleSample,
    onDismiss,
    className = '',
}) {
    const { t } = useTranslation();

    const total = steps.length;
    const done = steps.filter((s) => s.done).length;
    const completion = total > 0 ? Math.round((done / total) * 100) : 0;
    const value = percent ?? completion;
    const allDone = total > 0 && done === total;

    if (total === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="onboarding-checklist-title"
            className={[
                'rounded-xl border border-brand-100 bg-white p-5 shadow-soft dark:border-brand-800/60 dark:bg-neutral-900',
                className,
            ].join(' ')}
            data-testid="onboarding-checklist"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300">
                        <Rocket className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 id="onboarding-checklist-title" className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                            {allDone
                                ? t('onboarding_checklist.done_title', { defaultValue: 'You are all set 🎉' })
                                : t('onboarding_checklist.title', { defaultValue: 'Get started in three steps' })}
                        </h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                            {allDone
                                ? t('onboarding_checklist.done_body', { defaultValue: 'Your workspace is ready. Explore the dashboard or invite your team.' })
                                : t('onboarding_checklist.body', { defaultValue: 'Connect a channel, import your contacts and let automation handle the follow-up.' })}
                        </p>
                    </div>
                </div>
                {onDismiss && (
                    <button
                        type="button"
                        onClick={onDismiss}
                        aria-label={t('onboarding_checklist.dismiss', { defaultValue: 'Dismiss checklist' })}
                        className="rounded p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                    >
                        <X className="h-4 w-4" aria-hidden="true" />
                    </button>
                )}
            </div>

            {/* Progress */}
            <div className="mt-4">
                <div className="mb-1 flex items-center justify-between text-xs text-neutral-500 dark:text-neutral-400">
                    <span>{t('onboarding_checklist.progress', { defaultValue: 'Progress' })}</span>
                    <span className="font-semibold text-neutral-700 dark:text-neutral-300" aria-live="polite">{value}%</span>
                </div>
                <div
                    className="h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800"
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={value}
                >
                    <div className="h-full rounded-full bg-brand-500 transition-all duration-300" style={{ width: `${value}%` }} />
                </div>
            </div>

            {/* Steps */}
            <ol className="mt-4 space-y-2">
                {steps.map((step, index) => {
                    const content = (
                        <>
                            <span
                                className={[
                                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold',
                                    step.done
                                        ? 'border-brand-600 bg-brand-600 text-white'
                                        : 'border-neutral-300 text-neutral-500 dark:border-neutral-600 dark:text-neutral-400',
                                ].join(' ')}
                                aria-hidden="true"
                            >
                                {step.done ? <Check className="h-3.5 w-3.5" /> : <Circle className="h-2 w-2 fill-current" />}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className={[
                                    'block text-sm font-medium',
                                    step.done ? 'text-neutral-500 line-through dark:text-neutral-400' : 'text-neutral-800 dark:text-neutral-200',
                                ].join(' ')}>
                                    {step.title}
                                </span>
                                {step.description && (
                                    <span className="block text-xs text-neutral-500 dark:text-neutral-400">{step.description}</span>
                                )}
                            </span>
                            {!step.done && (step.href || step.onAction) && (
                                <span className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 dark:text-brand-400">
                                    {t('onboarding_checklist.start', { defaultValue: 'Start' })}
                                    <ArrowRight className="h-3 w-3 rtl:rotate-180" aria-hidden="true" />
                                </span>
                            )}
                        </>
                    );

                    const rowClass = [
                        'flex items-center gap-3 rounded-lg px-3 py-2 transition',
                        step.done ? 'bg-neutral-50 dark:bg-neutral-800/40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/60',
                        !step.done && (step.href || step.onAction) ? 'cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500' : '',
                    ].join(' ');

                    return (
                        <li key={step.key ?? index}>
                            {step.href ? (
                                <Link href={step.href} className={rowClass}>{content}</Link>
                            ) : step.onAction ? (
                                <button type="button" onClick={step.onAction} className={`${rowClass} w-full text-start`}>{content}</button>
                            ) : (
                                <div className={rowClass}>{content}</div>
                            )}
                        </li>
                    );
                })}
            </ol>

            {/* Sample data toggle */}
            {onToggleSample && (
                <div className="mt-4 flex items-start justify-between gap-3 rounded-lg border border-dashed border-neutral-200 px-3 py-2.5 dark:border-neutral-700">
                    <div className="flex items-start gap-2">
                        <FlaskConical className="mt-0.5 h-4 w-4 shrink-0 text-neutral-500 dark:text-neutral-400" aria-hidden="true" />
                        <div>
                            <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                {t('onboarding_checklist.sample_title', { defaultValue: 'Preview with sample data' })}
                            </p>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                {t('onboarding_checklist.sample_body', { defaultValue: 'Shows demo numbers on this dashboard. Turn it off anytime — nothing is saved to your workspace.' })}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={sampleEnabled}
                        onClick={() => onToggleSample(!sampleEnabled)}
                        aria-label={t('onboarding_checklist.sample_toggle', { defaultValue: 'Toggle sample data' })}
                        className={[
                            'relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors',
                            sampleEnabled ? 'bg-brand-600' : 'bg-neutral-300 dark:bg-neutral-600',
                        ].join(' ')}
                    >
                        <span
                            className={[
                                'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                sampleEnabled ? 'translate-x-4 rtl:-translate-x-4' : 'translate-x-0.5 rtl:-translate-x-0.5',
                            ].join(' ')}
                        />
                    </button>
                </div>
            )}
        </section>
    );
}
