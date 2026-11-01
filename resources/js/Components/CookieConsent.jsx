import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Cookie, X } from 'lucide-react';

const STORAGE_KEY = 'cookie_consent';

/**
 * Minimal cookie-consent banner.
 *
 * The choice is stored in localStorage instead of a cookie so declining the
 * banner still works without setting one. Non-essential scripts are expected to
 * read the stored value (`accepted` | `essential`) before loading.
 */
export default function CookieConsent() {
    const { t } = useTranslation();
    const { cookie_consent_enabled: enabled } = usePage().props;
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!enabled) return;

        try {
            if (! window.localStorage.getItem(STORAGE_KEY)) {
                setVisible(true);
            }
        } catch {
            // Storage blocked (private mode / strict settings) — stay silent
            // rather than nagging on every page load.
        }
    }, [enabled]);

    const choose = (value) => {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // Ignore: the banner will reappear next visit.
        }
        setVisible(false);
    };

    if (!enabled || !visible) return null;

    return (
        <div
            role="dialog"
            aria-live="polite"
            aria-label={t('cookie_consent.title') || 'Cookie consent'}
            className="fixed inset-x-0 bottom-0 z-[60] p-3 sm:p-4"
        >
            <div className="mx-auto flex max-w-3xl flex-col gap-3 rounded-soft-lg border border-neutral-200 bg-white p-4 shadow-soft-lg dark:border-neutral-700 dark:bg-neutral-900 sm:flex-row sm:items-center sm:gap-4">
                <div className="flex flex-1 items-start gap-3">
                    <Cookie className="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
                    <div>
                        <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('cookie_consent.title') || 'We use cookies'}
                        </p>
                        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            {t('cookie_consent.message') || 'Essential cookies keep the app working. With your permission we also use analytics cookies to improve it.'}{' '}
                            <Link href="/p/cookies" className="font-medium text-brand-600 hover:underline dark:text-brand-400">
                                {t('cookie_consent.learn_more') || 'Cookie policy'}
                            </Link>
                        </p>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        onClick={() => choose('essential')}
                        className="rounded-soft border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        {t('cookie_consent.essential_only') || 'Essential only'}
                    </button>
                    <button
                        type="button"
                        onClick={() => choose('accepted')}
                        className="rounded-soft bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700"
                    >
                        {t('cookie_consent.accept_all') || 'Accept all'}
                    </button>
                    <button
                        type="button"
                        onClick={() => choose('essential')}
                        aria-label={t('common.dismiss') || 'Dismiss'}
                        className="rounded-soft p-1.5 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>
    );
}
