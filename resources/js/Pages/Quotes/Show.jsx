import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    ArrowLeft, FileText, FileDown, Pencil, Trash2, Send, CheckCircle2, XCircle,
    FilePlus2, Copy, Check, Eye, Clock, Ban,
} from 'lucide-react';

const statusStyles = {
    draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    sent: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    viewed: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    accepted: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
    expired: 'bg-orange-100 text-orange-800 dark:bg-orange-950/60 dark:text-orange-300',
};

function money(value, currency) {
    return `${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${currency ? ` ${currency}` : ''}`;
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString();
}

function formatDateTime(value) {
    if (!value) return null;
    return new Date(value).toLocaleString();
}

function lineQuantity(value) {
    const number = Number(value) || 0;
    return Number.isInteger(number) ? String(number) : number.toFixed(2);
}

export default function QuoteShow({ quote = {}, publicUrl = '' }) {
    const { t } = useTranslation();
    const { flash, errors = {} } = usePage().props;

    const [deleting, setDeleting] = useState(false);
    const [copied, setCopied] = useState(false);

    const run = (name, id, options = {}) => {
        router.post(route(name, id), {}, { preserveScroll: true, ...options });
    };

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(publicUrl);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        } catch {
            setCopied(false);
        }
    };

    const remove = () => {
        router.delete(route('client.sales.quotes.destroy', quote.id), {
            onSuccess: () => setDeleting(false),
        });
    };

    const canEdit = !['accepted', 'rejected', 'expired'].includes(quote.status);
    const canAcceptReject = ['sent', 'viewed'].includes(quote.status);

    const timeline = [
        { key: 'sent_at', label: t('quotes.sent_at'), icon: <Send className="h-3.5 w-3.5" />, value: quote.sent_at },
        { key: 'viewed_at', label: t('quotes.viewed_at'), icon: <Eye className="h-3.5 w-3.5" />, value: quote.viewed_at },
        { key: 'accepted_at', label: t('quotes.accepted_at'), icon: <CheckCircle2 className="h-3.5 w-3.5" />, value: quote.accepted_at },
        { key: 'rejected_at', label: t('quotes.rejected_at'), icon: <XCircle className="h-3.5 w-3.5" />, value: quote.rejected_at },
    ].filter((entry) => entry.value);

    return (
        <ClientLayout>
            <Head title={quote.number || t('quotes.title')} />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <CheckCircle2 className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}

                {Object.keys(errors).length > 0 && (
                    <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                        {Object.values(errors)[0]}
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('client.sales.quotes.index')}
                            className="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">{quote.number}</h1>
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusStyles[quote.status] || statusStyles.draft}`}>
                                    {t(`quotes.status_${quote.status}`)}
                                </span>
                            </div>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('quotes.for_customer')}: {quote.company?.name || quote.lead?.name || t('quotes.no_customer')}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <a href={route('client.sales.quotes.pdf', quote.id)}>
                            <Button variant="secondary" className="inline-flex items-center gap-2">
                                <FileDown className="h-4 w-4" />
                                {t('quotes.pdf')}
                            </Button>
                        </a>

                        {canEdit && (
                            <>
                                <Link href={route('client.sales.quotes.edit', quote.id)}>
                                    <Button variant="secondary" className="inline-flex items-center gap-2">
                                        <Pencil className="h-4 w-4" />
                                        {t('common.edit')}
                                    </Button>
                                </Link>
                                <Button onClick={() => run('client.sales.quotes.send', quote.id)} className="inline-flex items-center gap-2">
                                    <Send className="h-4 w-4" />
                                    {quote.sent_at ? t('quotes.resend') : t('quotes.send')}
                                </Button>
                            </>
                        )}

                        {canAcceptReject && (
                            <>
                                <Button
                                    variant="secondary"
                                    onClick={() => run('client.sales.quotes.accept', quote.id)}
                                    className="inline-flex items-center gap-2"
                                >
                                    <CheckCircle2 className="h-4 w-4" />
                                    {t('quotes.mark_accepted')}
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => run('client.sales.quotes.reject', quote.id)}
                                    className="inline-flex items-center gap-2"
                                >
                                    <XCircle className="h-4 w-4" />
                                    {t('quotes.mark_rejected')}
                                </Button>
                            </>
                        )}

                        {quote.status === 'accepted' && !quote.invoice && (
                            <Button
                                onClick={() => run('client.sales.quotes.convert', quote.id)}
                                className="inline-flex items-center gap-2"
                            >
                                <FilePlus2 className="h-4 w-4" />
                                {t('quotes.convert')}
                            </Button>
                        )}

                        <button
                            type="button"
                            onClick={() => setDeleting(true)}
                            className="rounded-lg p-2 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                            title={t('common.delete')}
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    </div>
                </div>

                {quote.invoice && (
                    <div className="flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-sm dark:border-emerald-900 dark:bg-emerald-950/30">
                        <div className="flex items-center gap-2 text-emerald-800 dark:text-emerald-300">
                            <FileText className="h-4 w-4" />
                            {t('quotes.invoiced_as', { number: quote.invoice.number })}
                        </div>
                        <Link
                            href={route('client.sales.invoices.show', quote.invoice.id)}
                            className="font-medium text-emerald-700 hover:underline dark:text-emerald-300"
                        >
                            {t('quotes.view_invoice')}
                        </Link>
                    </div>
                )}

                <div className="grid gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        <div className="overflow-hidden rounded-2xl border border-soft bg-white dark:bg-neutral-900">
                            <div className="overflow-x-auto">
                                <table className="w-full text-start text-sm">
                                    <thead>
                                        <tr className="border-b border-soft bg-neutral-50/75 text-xs font-medium text-neutral-500 dark:bg-neutral-800/50 dark:text-neutral-400">
                                            <th className="px-5 py-3.5 text-start">{t('quotes.item_description')}</th>
                                            <th className="px-4 py-3.5 text-end">{t('quotes.item_qty')}</th>
                                            <th className="px-4 py-3.5 text-end">{t('quotes.item_price')}</th>
                                            <th className="px-4 py-3.5 text-end">{t('quotes.item_tax')}</th>
                                            <th className="px-5 py-3.5 text-end">{t('quotes.total')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-soft">
                                        {(quote.items || []).map((item) => (
                                            <tr key={item.id}>
                                                <td className="px-5 py-4">
                                                    <div className="font-medium text-neutral-900 dark:text-neutral-100">{item.description}</div>
                                                    {item.product?.name && (
                                                        <div className="text-[11px] text-neutral-400">{item.product.name}</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{lineQuantity(item.quantity)}</td>
                                                <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{money(item.unit_price)}</td>
                                                <td className="px-4 py-4 text-end tabular-nums text-neutral-500">{Number(item.tax_rate || 0)}%</td>
                                                <td className="px-5 py-4 text-end tabular-nums font-medium text-neutral-900 dark:text-neutral-100">{money(item.total)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {(quote.notes || quote.terms) && (
                            <div className="grid gap-5 sm:grid-cols-2">
                                {quote.notes && (
                                    <div className="rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                                        <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('quotes.notes')}</h2>
                                        <p className="mt-2 whitespace-pre-line text-sm text-neutral-700 dark:text-neutral-300">{quote.notes}</p>
                                    </div>
                                )}
                                {quote.terms && (
                                    <div className="rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                                        <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('quotes.terms')}</h2>
                                        <p className="mt-2 whitespace-pre-line text-sm text-neutral-700 dark:text-neutral-300">{quote.terms}</p>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="space-y-5">
                        <div className="space-y-3 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('quotes.subtotal')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">{money(quote.subtotal)}</dd>
                                </div>
                                {Number(quote.discount_value) > 0 && (
                                    <div className="flex justify-between">
                                        <dt className="text-neutral-500">{t('quotes.discount')}</dt>
                                        <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">
                                            -{money(quote.discount_value)}{quote.discount_type === 'percent' ? '%' : ''}
                                        </dd>
                                    </div>
                                )}
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('quotes.tax')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">{money(quote.tax_total)}</dd>
                                </div>
                                <div className="flex justify-between border-t border-soft pt-2 text-base font-bold">
                                    <dt>{t('quotes.total')}</dt>
                                    <dd className="tabular-nums">{money(quote.total, quote.currency)}</dd>
                                </div>
                            </dl>

                            <div className="pt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                <div className="flex justify-between">
                                    <span>{t('quotes.valid_until')}</span>
                                    <span className="text-neutral-700 dark:text-neutral-300">{formatDate(quote.valid_until)}</span>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-2 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('quotes.public_link')}</h2>
                            <p className="text-[11px] text-neutral-400">{t('quotes.public_link_hint')}</p>
                            <div className="flex gap-2">
                                <input
                                    readOnly
                                    value={publicUrl}
                                    onFocus={(event) => event.target.select()}
                                    className="min-w-0 flex-1 rounded-lg border-soft bg-neutral-50 p-2 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                />
                                <Button variant="secondary" size="sm" onClick={copyLink} className="inline-flex items-center gap-1.5">
                                    {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                                    {copied ? t('common.copied') : t('common.copy')}
                                </Button>
                            </div>
                        </div>

                        {timeline.length > 0 && (
                            <div className="space-y-3 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                                <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('quotes.timeline')}</h2>
                                <ol className="space-y-3">
                                    {timeline.map((entry) => (
                                        <li key={entry.key} className="flex items-start gap-2 text-sm">
                                            <span className="mt-0.5 text-brand-600 dark:text-brand-400">{entry.icon}</span>
                                            <div>
                                                <div className="text-neutral-700 dark:text-neutral-300">{entry.label}</div>
                                                <div className="text-[11px] text-neutral-400">{formatDateTime(entry.value)}</div>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        )}

                        {quote.status === 'expired' && (
                            <div className="flex items-center gap-2 rounded-2xl border border-orange-200 bg-orange-50/60 px-4 py-3 text-xs text-orange-700 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-300">
                                <Ban className="h-4 w-4" />
                                {t('quotes.expired_hint')}
                            </div>
                        )}

                        {quote.status === 'sent' && (
                            <div className="flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                                <Clock className="h-4 w-4" />
                                {t('quotes.awaiting_view')}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={deleting} onClose={() => setDeleting(false)} maxWidth="sm">
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">{t('quotes.delete_title')}</h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('quotes.delete_body')}</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleting(false)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={remove}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}
