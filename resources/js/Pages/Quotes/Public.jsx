import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { CheckCircle2, XCircle, FileDown, Loader2, ShieldCheck } from 'lucide-react';

const statusStyles = {
    draft: 'bg-neutral-100 text-neutral-600',
    sent: 'bg-amber-100 text-amber-800',
    viewed: 'bg-sky-100 text-sky-800',
    accepted: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-red-100 text-red-800',
    expired: 'bg-orange-100 text-orange-800',
};

function money(value, currency) {
    return `${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${currency ? ` ${currency}` : ''}`;
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString();
}

function lineQuantity(value) {
    const number = Number(value) || 0;
    return Number.isInteger(number) ? String(number) : number.toFixed(2);
}

export default function PublicQuote({ quote = {}, pdfUrl = '', accepted = false }) {
    const { t } = useTranslation();
    const { flash, errors = {} } = usePage().props;

    const [processing, setProcessing] = useState(null);

    const act = (action) => {
        setProcessing(action);
        router.post(
            route(action === 'accept' ? 'quotes.public.accept' : 'quotes.public.reject', quote.public_token),
            {},
            { onFinish: () => setProcessing(null) },
        );
    };

    const isAccepted = accepted || quote.status === 'accepted';
    const isRejected = quote.status === 'rejected';
    const isClosed = isAccepted || isRejected || quote.status === 'expired';
    const items = quote.items || [];

    return (
        <div className="min-h-screen bg-neutral-100 px-4 py-10 text-neutral-800 dark:bg-neutral-950 dark:text-neutral-200">
            <Head title={quote.number} />

            <div className="mx-auto w-full max-w-3xl space-y-4">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <CheckCircle2 className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}

                {Object.keys(errors).length > 0 && (
                    <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                        {Object.values(errors)[0]}
                    </div>
                )}

                <div className="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-4 border-b border-neutral-100 p-6 dark:border-neutral-800 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="text-sm font-semibold text-brand-600 dark:text-brand-400">
                                {quote.workspace?.name || ''}
                            </div>
                            <h1 className="mt-1 text-2xl font-bold">{quote.number}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('quotes.for_customer')}: {quote.company?.name || quote.lead?.name || t('quotes.no_customer')}
                            </p>
                        </div>

                        <div className="text-start sm:text-end">
                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusStyles[quote.status] || statusStyles.draft}`}>
                                {t(`quotes.status_${quote.status}`)}
                            </span>
                            <div className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                                {t('quotes.valid_until')}: {formatDate(quote.valid_until)}
                            </div>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-start text-sm">
                            <thead>
                                <tr className="border-b border-neutral-100 bg-neutral-50/70 text-xs font-medium text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/50">
                                    <th className="px-6 py-3 text-start">{t('quotes.item_description')}</th>
                                    <th className="px-4 py-3 text-end">{t('quotes.item_qty')}</th>
                                    <th className="px-4 py-3 text-end">{t('quotes.item_price')}</th>
                                    <th className="px-4 py-3 text-end">{t('quotes.item_tax')}</th>
                                    <th className="px-6 py-3 text-end">{t('quotes.total')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-6 py-4">{item.description}</td>
                                        <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{lineQuantity(item.quantity)}</td>
                                        <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{money(item.unit_price)}</td>
                                        <td className="px-4 py-4 text-end tabular-nums text-neutral-500">{Number(item.tax_rate || 0)}%</td>
                                        <td className="px-6 py-4 text-end tabular-nums font-medium">{money(item.total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex justify-end border-t border-neutral-100 p-6 dark:border-neutral-800">
                        <dl className="w-full max-w-xs space-y-2 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-neutral-500">{t('quotes.subtotal')}</dt>
                                <dd className="tabular-nums">{money(quote.subtotal)}</dd>
                            </div>
                            {Number(quote.discount_value) > 0 && (
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('quotes.discount')}</dt>
                                    <dd className="tabular-nums">-{money(quote.discount_value)}{quote.discount_type === 'percent' ? '%' : ''}</dd>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <dt className="text-neutral-500">{t('quotes.tax')}</dt>
                                <dd className="tabular-nums">{money(quote.tax_total)}</dd>
                            </div>
                            <div className="flex justify-between border-t border-neutral-200 pt-2 text-lg font-bold dark:border-neutral-700">
                                <dt>{t('quotes.total')}</dt>
                                <dd className="tabular-nums">{money(quote.total, quote.currency)}</dd>
                            </div>
                        </dl>
                    </div>

                    {(quote.notes || quote.terms) && (
                        <div className="space-y-4 border-t border-neutral-100 p-6 text-sm dark:border-neutral-800">
                            {quote.notes && (
                                <div>
                                    <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('quotes.notes')}</h2>
                                    <p className="mt-1 whitespace-pre-line text-neutral-600 dark:text-neutral-300">{quote.notes}</p>
                                </div>
                            )}
                            {quote.terms && (
                                <div>
                                    <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('quotes.terms')}</h2>
                                    <p className="mt-1 whitespace-pre-line text-neutral-600 dark:text-neutral-300">{quote.terms}</p>
                                </div>
                            )}
                        </div>
                    )}

                    <div className="flex flex-col gap-3 border-t border-neutral-100 bg-neutral-50/60 p-6 dark:border-neutral-800 dark:bg-neutral-900/60 sm:flex-row sm:items-center sm:justify-between">
                        <a
                            href={pdfUrl}
                            className="inline-flex items-center gap-2 text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white"
                        >
                            <FileDown className="h-4 w-4" />
                            {t('quotes.download_pdf')}
                        </a>

                        {isClosed ? (
                            <div className="inline-flex items-center gap-2 text-sm font-medium text-neutral-500 dark:text-neutral-400">
                                {isAccepted && <CheckCircle2 className="h-4 w-4 text-emerald-500" />}
                                {isRejected && <XCircle className="h-4 w-4 text-red-500" />}
                                {isAccepted ? t('quotes.accepted_thanks') : isRejected ? t('quotes.rejected_thanks') : t('quotes.expired_hint')}
                            </div>
                        ) : (
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => act('reject')}
                                    disabled={processing !== null}
                                    className="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                >
                                    {processing === 'reject' ? <Loader2 className="h-4 w-4 animate-spin" /> : <XCircle className="h-4 w-4" />}
                                    {t('quotes.reject')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => act('accept')}
                                    disabled={processing !== null}
                                    className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-700 disabled:opacity-50"
                                >
                                    {processing === 'accept' ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                                    {t('quotes.accept')}
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                <p className="flex items-center justify-center gap-1.5 text-center text-xs text-neutral-400">
                    <ShieldCheck className="h-3.5 w-3.5" />
                    {t('quotes.public_footer', { app: quote.workspace?.name || '' })}
                </p>
            </div>
        </div>
    );
}
