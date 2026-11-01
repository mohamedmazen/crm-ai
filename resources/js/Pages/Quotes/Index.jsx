import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import { FileText, Plus, Search, Pencil, Trash2, CheckCircle2, Eye, FileDown } from 'lucide-react';

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

export default function QuotesIndex({ quotes = { data: [], links: [], last_page: 1 }, filters = {}, stats = {}, defaultCurrency = null }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [query, setQuery] = useState(filters.q || '');
    const [status, setStatus] = useState(filters.status || '');
    const [deleting, setDeleting] = useState(null);

    const applyFilters = (event) => {
        event?.preventDefault();
        router.get(route('client.sales.quotes.index'), {
            q: query || undefined,
            status: status || undefined,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const remove = () => {
        if (!deleting) return;

        router.delete(route('client.sales.quotes.destroy', deleting.id), {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
        });
    };

    const statCards = [
        { label: t('quotes.stats_total'), value: stats.total ?? 0 },
        { label: t('quotes.stats_open'), value: stats.open ?? 0 },
        { label: t('quotes.stats_accepted'), value: stats.accepted ?? 0 },
        { label: t('quotes.stats_value'), value: money(stats.accepted_value, defaultCurrency) },
    ];

    return (
        <ClientLayout>
            <Head title={t('quotes.title')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <CheckCircle2 className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                            <FileText className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">{t('quotes.title')}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('quotes.subtitle')}</p>
                        </div>
                    </div>

                    <Link href={route('client.sales.quotes.create')}>
                        <Button className="inline-flex items-center gap-2">
                            <Plus className="h-4 w-4" />
                            {t('quotes.new')}
                        </Button>
                    </Link>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statCards.map(({ label, value }) => (
                        <div key={label} className="rounded-2xl border border-soft bg-white p-4 dark:bg-neutral-900">
                            <div className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</div>
                            <div className="mt-1 text-2xl font-bold tabular-nums text-neutral-900 dark:text-neutral-100">{value}</div>
                        </div>
                    ))}
                </div>

                <form onSubmit={applyFilters} className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('quotes.search_placeholder')}
                            className="w-full rounded-lg border-soft bg-white py-2 pe-3 ps-9 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                        />
                    </div>

                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="rounded-lg border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                    >
                        <option value="">{t('quotes.all_statuses')}</option>
                        {['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired'].map((value) => (
                            <option key={value} value={value}>{t(`quotes.status_${value}`)}</option>
                        ))}
                    </select>

                    <Button type="submit" variant="secondary">{t('common.filter')}</Button>
                </form>

                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    {quotes.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800">
                                <FileText className="h-7 w-7" />
                            </div>
                            <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-neutral-100">{t('quotes.empty_title')}</h3>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-neutral-500 dark:text-neutral-400">{t('quotes.empty_subtitle')}</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 text-xs font-medium text-neutral-500 dark:bg-neutral-800/50 dark:text-neutral-400">
                                        <th className="px-5 py-3.5 text-start">{t('quotes.th_number')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('quotes.th_customer')}</th>
                                        <th className="px-4 py-3.5 text-center">{t('quotes.th_items')}</th>
                                        <th className="px-4 py-3.5 text-end">{t('quotes.th_total')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('common.status')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('quotes.th_valid_until')}</th>
                                        <th className="px-5 py-3.5 text-end">{t('common.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {quotes.data.map((quote) => (
                                        <tr key={quote.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-4 font-medium text-neutral-900 dark:text-neutral-100">
                                                <Link href={route('client.sales.quotes.show', quote.id)} className="hover:text-brand-600 dark:hover:text-brand-400">
                                                    {quote.number}
                                                </Link>
                                                {quote.invoice && (
                                                    <div className="text-[11px] text-neutral-400">{t('quotes.invoiced_as', { number: quote.invoice.number })}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <div className="text-neutral-700 dark:text-neutral-200">{quote.company?.name || quote.lead?.name || '—'}</div>
                                                {quote.lead?.email && <div className="text-[11px] text-neutral-400">{quote.lead.email}</div>}
                                            </td>
                                            <td className="px-4 py-4 text-center tabular-nums text-neutral-500">{quote.items_count ?? 0}</td>
                                            <td className="px-4 py-4 text-end tabular-nums font-medium text-neutral-900 dark:text-neutral-100">
                                                {money(quote.total, quote.currency || defaultCurrency)}
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusStyles[quote.status] || statusStyles.draft}`}>
                                                    {t(`quotes.status_${quote.status}`)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4 text-neutral-500">
                                                {quote.valid_until ? new Date(quote.valid_until).toLocaleDateString() : '—'}
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link href={route('client.sales.quotes.show', quote.id)} className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800" title={t('common.view')}>
                                                        <Eye className="h-4 w-4" />
                                                    </Link>
                                                    <Link href={route('client.sales.quotes.edit', quote.id)} className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800" title={t('common.edit')}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                    <a href={route('client.sales.quotes.pdf', quote.id)} className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800" title={t('quotes.pdf')}>
                                                        <FileDown className="h-4 w-4" />
                                                    </a>
                                                    <button type="button" onClick={() => setDeleting(quote)} className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" title={t('common.delete')}>
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {quotes.last_page > 1 && (
                        <div className="flex gap-1 border-t border-soft px-4 py-3">
                            {quotes.links.map((link, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    disabled={!link.url || link.active}
                                    onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
                                    className={`rounded px-3 py-1.5 text-sm ${
                                        link.active ? 'bg-brand-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                    } ${!link.url ? 'cursor-not-allowed opacity-40' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <Modal show={Boolean(deleting)} onClose={() => setDeleting(null)} maxWidth="sm">
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">{t('quotes.delete_title')}</h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('quotes.delete_body')}</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={remove}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}