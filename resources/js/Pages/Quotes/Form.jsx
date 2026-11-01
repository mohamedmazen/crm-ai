import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

function toDateInput(value) {
    if (!value) return '';
    return String(value).slice(0, 10);
}

function blankItem() {
    return { product_id: '', description: '', quantity: 1, unit_price: 0, tax_rate: 0 };
}

export default function QuoteForm({
    quote = null,
    products = [],
    leads = [],
    companies = [],
    currencies = [],
    defaultCurrency = null,
}) {
    const { t } = useTranslation();
    const editing = Boolean(quote);

    const form = useForm({
        lead_id: quote?.lead_id ?? '',
        company_id: quote?.company_id ?? '',
        valid_until: toDateInput(quote?.valid_until),
        discount_type: quote?.discount_type ?? '',
        discount_value: quote?.discount_value ?? 0,
        currency: quote?.currency ?? defaultCurrency ?? '',
        notes: quote?.notes ?? '',
        terms: quote?.terms ?? '',
        items: quote?.items?.length
            ? quote.items.map((item) => ({
                product_id: item.product_id ?? '',
                description: item.description ?? '',
                quantity: item.quantity ?? 1,
                unit_price: item.unit_price ?? 0,
                tax_rate: item.tax_rate ?? 0,
            }))
            : [blankItem()],
    });

    const [productChoice, setProductChoice] = useState({});

    const totals = useMemo(() => {
        let subtotal = 0;
        let lineTax = 0;

        form.data.items.forEach((item) => {
            const line = Math.round((Number(item.quantity) || 0) * (Number(item.unit_price) || 0) * 100) / 100;
            subtotal += line;
            lineTax += Math.round(line * ((Number(item.tax_rate) || 0) / 100) * 100) / 100;
        });

        const value = Number(form.data.discount_value) || 0;
        let discount = 0;

        if (form.data.discount_type === 'percent') {
            discount = Math.round(subtotal * (Math.min(100, Math.max(0, value)) / 100) * 100) / 100;
        } else if (form.data.discount_type === 'fixed') {
            discount = Math.min(value, subtotal);
        }

        const ratio = subtotal > 0 ? (subtotal - discount) / subtotal : 0;
        const tax = Math.round(lineTax * ratio * 100) / 100;

        return {
            subtotal: subtotal.toFixed(2),
            tax: tax.toFixed(2),
            discount: discount.toFixed(2),
            total: (subtotal - discount + tax).toFixed(2),
        };
    }, [form.data.items, form.data.discount_type, form.data.discount_value]);

    const setItem = (index, key, value) => {
        const items = form.data.items.map((item, itemIndex) => (
            itemIndex === index ? { ...item, [key]: value } : item
        ));
        form.setData('items', items);
    };

    const pickProduct = (index, productId) => {
        setProductChoice({ ...productChoice, [index]: productId });

        if (!productId) {
            setItem(index, 'product_id', '');
            return;
        }

        const product = products.find((candidate) => String(candidate.id) === String(productId));

        if (!product) return;

        const items = form.data.items.map((item, itemIndex) => (
            itemIndex === index
                ? {
                    ...item,
                    product_id: product.id,
                    description: product.name,
                    unit_price: product.price,
                    tax_rate: product.tax_rate ?? 0,
                }
                : item
        ));

        form.setData('items', items);
    };

    const addItem = () => form.setData('items', [...form.data.items, blankItem()]);

    const removeItem = (index) => {
        if (form.data.items.length === 1) return;
        form.setData('items', form.data.items.filter((_, itemIndex) => itemIndex !== index));
    };

    const submit = (event) => {
        event.preventDefault();

        if (editing) {
            form.put(route('client.sales.quotes.update', quote.id));
        } else {
            form.post(route('client.sales.quotes.store'));
        }
    };

    return (
        <ClientLayout>
            <Head title={editing ? t('quotes.edit') : t('quotes.new')} />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link href={route('client.sales.quotes.index')} className="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">
                                {editing ? t('quotes.edit') : t('quotes.new')}
                            </h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('quotes.form_subtitle')}</p>
                        </div>
                    </div>

                    {editing && (
                        <span className="text-sm font-medium text-neutral-500">{quote.number}</span>
                    )}
                </div>

                <form onSubmit={submit} className="space-y-5">
                    <div className="rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('quotes.lead')}</label>
                                <select
                                    value={form.data.lead_id ?? ''}
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
                                    <option value="">{t('quotes.no_lead')}</option>
                                    {leads.map((lead) => (
                                        <option key={lead.id} value={lead.id}>{lead.name}{lead.email ? ` · ${lead.email}` : ''}</option>
                                    ))}
                                </select>
                                {form.errors.lead_id && <p className="mt-1 text-xs text-red-600">{form.errors.lead_id}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('quotes.company')}</label>
                                <select
                                    value={form.data.company_id ?? ''}
                                    onChange={(event) => form.setData('company_id', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="">{t('quotes.no_company')}</option>
                                    {companies.map((company) => (
                                        <option key={company.id} value={company.id}>{company.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('quotes.valid_until')}</label>
                                <input
                                    type="date"
                                    value={form.data.valid_until}
                                    onChange={(event) => form.setData('valid_until', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                                {form.errors.valid_until && <p className="mt-1 text-xs text-red-600">{form.errors.valid_until}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('quotes.currency')}</label>
                                <select
                                    value={form.data.currency ?? ''}
                                    onChange={(event) => form.setData('currency', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                >
                                    <option value="">{t('products.workspace_default')}</option>
                                    {currencies.map((code) => <option key={code} value={code}>{code}</option>)}
                                </select>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-bold text-neutral-900 dark:text-neutral-100">{t('quotes.items')}</h2>
                            <Button type="button" size="sm" variant="secondary" onClick={addItem} className="inline-flex items-center gap-1.5">
                                <Plus className="h-3.5 w-3.5" />
                                {t('quotes.add_item')}
                            </Button>
                        </div>

                        {form.errors.items && <p className="mb-2 text-xs text-red-600">{form.errors.items}</p>}

                        <div className="space-y-3">
                            {form.data.items.map((item, index) => (
                                <div key={index} className="rounded-xl border border-neutral-100 p-3 dark:border-neutral-800">
                                    <div className="grid gap-2 sm:grid-cols-12">
                                        <div className="sm:col-span-4">
                                            <label className="block text-[11px] font-medium text-neutral-500">{t('quotes.item_product')}</label>
                                            <select
                                                value={productChoice[index] ?? item.product_id ?? ''}
                                                onChange={(event) => pickProduct(index, event.target.value)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                            >
                                                <option value="">{t('quotes.custom_item')}</option>
                                                {products.map((product) => (
                                                    <option key={product.id} value={product.id}>{product.name}{product.sku ? ` (${product.sku})` : ''}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div className="sm:col-span-4">
                                            <label className="block text-[11px] font-medium text-neutral-500">{t('quotes.item_description')} *</label>
                                            <input
                                                value={item.description}
                                                onChange={(event) => setItem(index, 'description', event.target.value)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                            />
                                            {form.errors[`items.${index}.description`] && (
                                                <p className="mt-1 text-[11px] text-red-600">{form.errors[`items.${index}.description`]}</p>
                                            )}
                                        </div>

                                        <div className="sm:col-span-1">
                                            <label className="block text-[11px] font-medium text-neutral-500">{t('quotes.item_qty')}</label>
                                            <input
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                value={item.quantity}
                                                onChange={(event) => setItem(index, 'quantity', event.target.value)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                            />
                                        </div>

                                        <div className="sm:col-span-1">
                                            <label className="block text-[11px] font-medium text-neutral-500">{t('quotes.item_price')}</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={item.unit_price}
                                                onChange={(event) => setItem(index, 'unit_price', event.target.value)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                            />
                                        </div>

                                        <div className="sm:col-span-1">
                                            <label className="block text-[11px] font-medium text-neutral-500">{t('quotes.item_tax')}</label>
                                            <input
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value={item.tax_rate}
                                                onChange={(event) => setItem(index, 'tax_rate', event.target.value)}
                                                className="mt-1 w-full rounded-lg border-soft bg-white p-2 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                            />
                                        </div>

                                        <div className="flex items-end justify-end sm:col-span-1">
                                            <button
                                                type="button"
                                                onClick={() => removeItem(index)}
                                                disabled={form.data.items.length === 1}
                                                className="rounded-lg p-2 text-neutral-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30 dark:hover:bg-red-900/30"
                                                title={t('quotes.remove_item')}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-5 lg:grid-cols-2">
                        <div className="space-y-4 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('quotes.discount')}</label>
                                <div className="mt-1 flex gap-2">
                                    <select
                                        value={form.data.discount_type ?? ''}
                                        onChange={(event) => form.setData('discount_type', event.target.value)}
                                        className="w-1/2 rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                    >
                                        <option value="">{t('quotes.no_discount')}</option>
                                        <option value="percent">{t('quotes.discount_percent')}</option>
                                        <option value="fixed">{t('quotes.discount_fixed')}</option>
                                    </select>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={form.data.discount_value}
                                        onChange={(event) => form.setData('discount_value', event.target.value)}
                                        disabled={!form.data.discount_type}
                                        className="w-1/2 rounded-lg border-soft bg-white p-2.5 text-sm disabled:opacity-40 dark:bg-neutral-800 dark:text-neutral-100"
                                    />
                                </div>
                                {form.errors.discount_value && <p className="mt-1 text-xs text-red-600">{form.errors.discount_value}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('quotes.notes')}</label>
                                <textarea
                                    rows={3}
                                    value={form.data.notes}
                                    onChange={(event) => form.setData('notes', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('quotes.terms')}</label>
                                <textarea
                                    rows={3}
                                    value={form.data.terms}
                                    onChange={(event) => form.setData('terms', event.target.value)}
                                    className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                                />
                            </div>
                        </div>

                        <div className="space-y-3 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <h2 className="text-sm font-bold text-neutral-900 dark:text-neutral-100">{t('quotes.summary')}</h2>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('quotes.subtotal')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">{totals.subtotal}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('quotes.discount')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">-{totals.discount}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('quotes.tax')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">{totals.tax}</dd>
                                </div>
                                <div className="flex justify-between border-t border-soft pt-2 text-base font-bold">
                                    <dt>{t('quotes.total')}</dt>
                                    <dd className="tabular-nums">{totals.total} {form.data.currency}</dd>
                                </div>
                            </dl>

                            <div className="flex justify-end gap-2 pt-3">
                                <Link href={route('client.sales.quotes.index')}>
                                    <Button type="button" variant="secondary">{t('common.cancel')}</Button>
                                </Link>
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing ? t('common.saving') : t('quotes.save')}
                                </Button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </ClientLayout>
    );
}