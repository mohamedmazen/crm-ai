import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { CheckCircle2 } from 'lucide-react';

export default function Unsubscribed({ sequenceName }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('sequences.unsubscribed_title') || 'Unsubscribed'} />

            <div className="flex min-h-screen items-center justify-center bg-neutral-50 px-4 dark:bg-neutral-950">
                <div className="w-full max-w-md rounded-2xl border border-soft bg-white p-8 text-center shadow-sm dark:bg-neutral-900">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <CheckCircle2 className="h-7 w-7" />
                    </div>

                    <h1 className="mt-4 text-xl font-bold text-neutral-900 dark:text-neutral-100">
                        {t('sequences.unsubscribed_title') || 'You have been unsubscribed'}
                    </h1>

                    <p className="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                        {sequenceName
                            ? (t('sequences.unsubscribed_body_named', { name: sequenceName })
                                || `You will no longer receive emails from “${sequenceName}”.`)
                            : (t('sequences.unsubscribed_body') || 'You will no longer receive these follow-up emails.')}
                    </p>
                </div>
            </div>
        </>
    );
}
