import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import { Package, Plus, Search, Pencil, Trash2, CheckCircle2, Archive } from 'lucide-react';

const statusStyles = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    archived: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

function money(value, currency) {
    return `${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${currency ? ` ${currency}` : ''}`;
}

export default function ProductsIndex({ products = { data: [], links: [], last_page: 1 }, filters = {}, currencies = [], defaultCurrency = null }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [query, setQuery] = useState(filters.q || '');
    const [status, setStatus] = useState(filters.status || '');
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const form = useForm({
        name: '',
        sku: '',
        price: '',
        currency: defaultCurrency || '',
        tax_rate: 0,
        unit: '',
        description: '',
        status: 'active',
    });

    const applyFilters = (event) => {
        event?.preventDefault();
        router.get(route('client.sales.products.index'), {
            q: query || undefined,
            status: status || undefined,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const openCreate = () => {
        setEditing(null);
        form.setData({
            name: '',
            sku: '',
            price: '',
            currency: defaultCurrency || '',
            tax_rate: 0,
            unit: '',
            description: '',
            status: 'active',
        });
        form.clearErrors();
        setShowForm(true);
    };

    const openEdit = (product) => {
        setEditing(product);
        form.setData({
            name: product.name ?? '',
            sku: product.sku ?? '',
            price: product.price ?? '',
            currency: product.currency ?? defaultCurrency ?? '',
            tax_rate: product.tax_rate ?? 0,
            unit: product.unit ?? '',
            description: product.description ?? '',
            status: product.status ?? 'active',
        });
        form.clearErrors();
        setShowForm(true);
    };

    const submit = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => setShowForm(false),
        };

        if (editing) {
            form.put(route('client.sales.products.update', editing.id), options);
        } else {
            form.post(route('client.sales.products.store'), options);
        }
    };

    const remove = () => {
        if (!deleting) return;

        router.delete(route('client.sales.products.destroy', deleting.id), {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
        });
    };

    return (
        <ClientLayout>
            <Head title={t('products.title')} />

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
                            <Package className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">{t('products.title')}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('products.subtitle')}</p>
                        </div>
                    </div>

                    <Button onClick={openCreate} className="inline-flex items-center gap-2">
                        <Plus className="h-4 w-4" />
                        {t('products.new')}
                    </Button>
                </div>

                <form onSubmit={applyFilters} className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('products.search_placeholder')}
                            className="w-full rounded-lg border-soft bg-white py-2 pe-3 ps-9 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                        />
                    </div>

                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="rounded-lg border-soft bg-white px-3 py-2 text-sm dark:bg-neutral-900 dark:text-neutral-100"
                    >
                        <option value="">{t('products.all_statuses')}</option>
                        <option value="active">{t('products.status_active')}</option>
                        <option value="archived">{t('products.status_archived')}</option>
                    </select>

                    <Button type="submit" variant="secondary">{t('common.filter')}</Button>
                </form>

                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    {products.data.length === 0 ? (
                        <div className="py-16 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neutral-100 text-neutral-400 dark:bg-neutral-800">
                                <Package className="h-7 w-7" />
                            </div>
                            <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-neutral-100">{t('products.empty_title')}</h3>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-neutral-500 dark:text-neutral-400">{t('products.empty_subtitle')}</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 text-xs font-medium text-neutral-500 dark:bg-neutral-800/50 dark:text-neutral-400">
                                        <th className="px-5 py-3.5 text-start">{t('products.th_product')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('products.th_sku')}</th>
                                        <th className="px-4 py-3.5 text-end">{t('products.th_price')}</th>
                                        <th className="px-4 py-3.5 text-end">{t('products.th_tax')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('products.th_unit')}</th>
                                        <th className="px-4 py-3.5 text-start">{t('common.status')}</th>
                                        <th className="px-5 py-3.5 text-end">{t('common.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {products.data.map((product) => (
                                        <tr key={product.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-4">
                                                <div className="font-medium text-neutral-900 dark:text-neutral-100">{product.name}</div>
                                                {product.description && (
                                                    <div className="mt-0.5 line-clamp-1 text-xs text-neutral-400">{product.description}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-4 font-mono text-xs text-neutral-500">{product.sku || '—'}</td>
                                            <td className="px-4 py-4 text-end tabular-nums text-neutral-700 dark:text-neutral-200">
                                                {money(product.price, product.currency || defaultCurrency)}
                                            </td>
                                            <td className="px-4 py-4 text-end tabular-nums text-neutral-500">{Number(product.tax_rate || 0)}%</td>
                                            <td className="px-4 py-4 text-neutral-500">{product.unit || '—'}</td>
                                            <td className="px-4 py-4">
                                                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusStyles[product.status] || statusStyles.archived}`}>
                                                    {product.status === 'archived' && <Archive className="h-3 w-3" />}
                                                    {t(`products.status_${product.status}`)}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="flex items-center justify-end gap-1">
                                                    <button
                                                        type="button"
                                                        onClick={() => openEdit(product)}
                                                        className="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-brand-600 dark:hover:bg-neutral-800"
                                                        title={t('common.edit')}
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleting(product)}
                                                        className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                                                        title={t('common.delete')}
                                                    >
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

                    {products.last_page > 1 && (
                        <div className="flex gap-1 border-t border-soft px-4 py-3">
                            {products.links.map((link, index) => (
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
                        <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">
                            {editing ? t('products.edit') : t('products.new')}
                        </h2>
                        <button type="button" onClick={() => setShowForm(false)} className="text-neutral-400 hover:text-neutral-600">&times;</button>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('products.name')} *</label>
                            <input
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                placeholder={t('products.name_placeholder')}
                            />
                            {form.errors.name && <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('products.sku')}</label>
                            <input
                                value={form.data.sku}
                                onChange={(event) => form.setData('sku', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                placeholder="SKU-001"
                            />
                            {form.errors.sku && <p className="mt-1 text-xs text-red-600">{form.errors.sku}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('products.unit')}</label>
                            <input
                                value={form.data.unit}
                                onChange={(event) => form.setData('unit', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                placeholder={t('products.unit_placeholder')}
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('products.price')} *</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.price}
                                onChange={(event) => form.setData('price', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                            {form.errors.price && <p className="mt-1 text-xs text-red-600">{form.errors.price}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('products.currency')}</label>
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
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('products.tax_rate')}</label>
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={form.data.tax_rate}
                                onChange={(event) => form.setData('tax_rate', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('common.status')}</label>
                            <select
                                value={form.data.status}
                                onChange={(event) => form.setData('status', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                <option value="active">{t('products.status_active')}</option>
                                <option value="archived">{t('products.status_archived')}</option>
                            </select>
                        </div>

                        <div className="sm:col-span-2">
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('products.description')}</label>
                            <textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
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
                    <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">{t('products.delete_title')}</h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('products.delete_body')}</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={remove}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}