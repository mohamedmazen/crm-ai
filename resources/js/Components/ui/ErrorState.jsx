import { AlertTriangle, RotateCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Shared error state (Phase 4 — polish & accessibility).
 *
 * Every data surface that can fail renders through this component so the user
 * always gets an explanation plus a Retry action instead of a blank screen.
 *
 * Props:
 *   title       – heading (defaults to a translated "Something went wrong").
 *   description – supporting paragraph / server message.
 *   onRetry     – called by the Retry button; omit to hide the button.
 *   retryLabel  – override the Retry label.
 *   compact     – inline variant for panels inside a card.
 *   className   – extra wrapper classes.
 */
export default function ErrorState({
    title,
    description,
    onRetry,
    retryLabel,
    compact = false,
    className = '',
}) {
    const { t } = useTranslation();

    return (
        <div
            role="alert"
            className={[
                'flex flex-col items-center justify-center text-center',
                compact ? 'px-4 py-6' : 'px-6 py-16',
                className,
            ].join(' ')}
        >
            <div
                className={[
                    'mb-3 flex items-center justify-center rounded-2xl bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400',
                    compact ? 'h-10 w-10' : 'h-16 w-16',
                ].join(' ')}
                aria-hidden="true"
            >
                <AlertTriangle className={compact ? 'h-5 w-5' : 'h-8 w-8'} />
            </div>
            <h2 className={compact ? 'text-sm font-semibold' : 'text-lg font-semibold'}>
                {title ?? t('state.error_title', { defaultValue: 'Something went wrong' })}
            </h2>
            {description && (
                <p className="mt-1 max-w-sm text-sm text-neutral-500 dark:text-neutral-400">
                    {description}
                </p>
            )}
            {onRetry && (
                <button
                    type="button"
                    onClick={onRetry}
                    className="mt-4 inline-flex items-center gap-2 rounded-soft bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-900"
                >
                    <RotateCw className="h-4 w-4" aria-hidden="true" />
                    {retryLabel ?? t('state.retry', { defaultValue: 'Try again' })}
                </button>
            )}
        </div>
    );
}
