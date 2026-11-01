import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import { Receipt, Plus, Search, Eye, FileDown, Trash2, CheckCircle2, AlertTriangle } from 'lucide-react';

const statusStyles = {
    draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    sent: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    viewed: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    paid: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    overdue: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
    cancelled: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-500',
};

function money(value, currency) {
    return `${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${currency ? ` ${currency}` : ''}`;
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString();
}

export default function InvoicesIndex({
    invoices = { data: [], links: [], last_page: 1 },
    filters = {},
    stats = {},
    leads = [],
    companies = [],
    currencies = [],
    defaultCurrency = null,
}) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [query, setQuery] = useState(filters.q || '');
    const [status, setStatus] = useState(filters.status || '');
    const [showForm, setShowForm] = useState(false);
    const [deleting, setDeleting] = useState(null);

    const form = useForm({
        lead_id: '',
        company_id: '',
        subtotal: '',
        tax_total: 0,
        total: '',
        currency: defaultCurrency || '',
        due_date: '',
        notes: '',
    });

    const applyFilters = (event) => {
        event?.preventDefault();
        router.get(route('client.sales.invoices.index'), {
            q: query || undefined,
            status: status || undefined,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const openCreate = () => {
        form.setData({
            lead_id: '',
            company_id: '',
            subtotal: '',
            tax_total: 0,
            total: '',
            currency: defaultCurrency || '',
            due_date: '',
            notes: '',
        });
        form.clearErrors();
        setShowForm(true);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('client.sales.invoices.store'), {
            preserveScroll: true,
            onSuccess: () => setShowForm(false),
        });
    };

    const remove = () => {
        if (!deleting) return;
        router.delete(route('client.sales.invoices.destroy', deleting.id), {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
        });
    };

    const statCards = [
        { label: t('invoices.stats_total'), value: stats.total ?? 0 },
        { label: t('invoices.stats_outstanding'), value: money(stats.outstanding, defaultCurrency) },
        { label: t('invoices.stats_paid'), value: money(stats.paid, defaultCurrency) },
        { label: t('invoices.stats_overdue'), value: stats.overdue ?? 0, danger: (stats.overdue ?? 0) > 0 },
    ];

    return (
        <ClientLayout>
            <Head title={t('invoices.title')} />

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
                            <Receipt className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">{t('invoices.title')}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('invoices.subtitle')}</p>
                        </div>
                    </div>

                    <Button onClick={openCreate} className="inline-flex items-center gap-2">
                        <Plus className="h-4 w-4" />
                        {t('invoices.new')}
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statCards.map(({ label, value, danger }) => (
                        <div key={label} className="rounded-2xl border border-soft bg-white p-4 dark:bg-neutral-900">
                            <div className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{label}</div>
                            <div className={`mt-1 text-2xl font-bold tabular-nums ${danger ? 'text-red-600 dark:text-red-400' : 'text-neutral-900 dark:text-neutral-100'}`}>{value}</div>
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
                            placeholder={t('invoices.search_placeholder')}
                            className="w-full rounded-lg border-soft bg-white py-2 pe-3 ps-9 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                        />
                    </div>

                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="rounded-lg border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                    >
                        <option value="">{t('invoices.all_statuses')}</option>
                        {['draft', 'sent', 'viewed', 'paid', 'overdue', 'cancelled'].map((value) => (
                            <option key={value} value={value}>{t(`invoices.status_${value}`)}</option>
                        ))}
                    </select>

                    <Button type="submit" variant="secondary">{t('common.filter')}</Button>
                </form>

                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    {invoices.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800">
                                <Receipt className="h-7 w-7" />
                            </div>
                            <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-neutral-100">{t('invoices.empty_title')}</h3>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-neutral-500 dark:text-neutral-400">{t('invoices.empty_subtitle')}</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 text-xs font-medium text-neutral-500 dark:bg-neutral-800/50 dark:text-neutral-400">
                                        <th className="px-5 py-3.5 text-start">{t('invoices.th_number')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('invoices.th_customer')}</th>
                                        <th className="px-4 py-3.5 text-end">{t('invoices.th_total')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('common.status')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('invoices.th_due')}</th>
                                        <th className="px-5 py-3.5 text-end">{t('common.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {invoices.data.map((invoice) => (
                                        <tr key={invoice.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-4 font-medium text-neutral-900 dark:text-neutral-100">
                                                <Link href={route('client.sales.invoices.show', invoice.id)} className="hover:text-brand-600 dark:hover:text-brand-400">
                                                    {invoice.number}
                                                </Link>
                                                {invoice.quote && (
                                                    <div className="text-[11px] text-neutral-400">{t('invoices.from_quote', { number: invoice.quote.number })}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <div className="text-neutral-700 dark:text-neutral-200">{invoice.company?.name || invoice.lead?.name || '—'}</div>
                                                {invoice.lead?.email && <div className="text-[11px] text-neutral-400">{invoice.lead.email}</div>}
                                            </td>
                                            <td className="px-4 py-4 text-end tabular-nums font-medium text-neutral-900 dark:text-neutral-100">
                                                {money(invoice.total, invoice.currency || defaultCurrency)}
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusStyles[invoice.status] || statusStyles.draft}`}>
                                                    {invoice.status === 'overdue' && <AlertTriangle className="h-3 w-3" />}
                                                    {t(`invoices.status_${invoice.status}`)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-4 text-neutral-500">{formatDate(invoice.due_date)}</td>
                                            <td className="px-5 py-4">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link href={route('client.sales.invoices.show', invoice.id)} className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800" title={t('common.view')}>
                                                        <Eye className="h-4 w-4" />
                                                    </Link>
                                                    <a href={route('client.sales.invoices.pdf', invoice.id)} className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800" title={t('invoices.pdf')}>
                                                        <FileDown className="h-4 w-4" />
                                                    </a>
                                                    <button type="button" onClick={() => setDeleting(invoice)} className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" title={t('common.delete')}>
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

                    {invoices.last_page > 1 && (
                        <div className="flex gap-1 border-t border-soft px-4 py-3">
                            {invoices.links.map((link, index) => (
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

            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="lg">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">{t('invoices.new')}</h2>
                        <button type="button" onClick={() => setShowForm(false)} className="text-neutral-400 hover:text-neutral-600">&times;</button>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.lead')}</label>
                            <select
                                value={form.data.lead_id}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    form.setData({
                                        ...form.data,
                                        lead_id: value,
                                        company_id: value
                                            ? (leads.find((lead) => String(lead.id) === String(value))?.company_id ?? form.data.company_id)
                                            : form.data.company_id,
                                    });
                                }}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="">{t('invoices.no_lead')}</option>
                                {leads.map((lead) => (
                                    <option key={lead.id} value={lead.id}>{lead.name}{lead.email ? ` · ${lead.email}` : ''}</option>
                                ))}
                            </select>
                            {form.errors.lead_id && <p className="mt-1 text-xs text-red-600">{form.errors.lead_id}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.company')}</label>
                            <select
                                value={form.data.company_id}
                                onChange={(event) => form.setData('company_id', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="">{t('invoices.no_company')}</option>
                                {companies.map((company) => (
                                    <option key={company.id} value={company.id}>{company.name}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.subtotal')} *</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.subtotal}
                                onChange={(event) => form.setData('subtotal', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                            {form.errors.subtotal && <p className="mt-1 text-xs text-red-600">{form.errors.subtotal}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.tax')}</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.tax_total}
                                onChange={(event) => form.setData('tax_total', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.total')}</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.total}
                                onChange={(event) => form.setData('total', event.target.value)}
                                placeholder={t('invoices.total_hint')}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.currency')}</label>
                            <select
                                value={form.data.currency}
                                onChange={(event) => form.setData('currency', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="">{t('products.workspace_default')}</option>
                                {currencies.map((code) => <option key={code} value={code}>{code}</option>)}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.due_date')}</label>
                            <input
                                type="date"
                                value={form.data.due_date}
                                onChange={(event) => form.setData('due_date', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.notes')}</label>
                            <textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>{t('common.cancel')}</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? t('common.saving') : t('common.save')}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal show={Boolean(deleting)} onClose={() => setDeleting(null)} maxWidth="sm">
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">{t('invoices.delete_title')}</h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('invoices.delete_body')}</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={remove}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}
