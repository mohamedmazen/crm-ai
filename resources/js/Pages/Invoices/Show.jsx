import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    ArrowLeft, FileDown, Send, Trash2, Pencil, Copy, Check, Plus,
    Wallet, AlertTriangle, CheckCircle2, Clock, Ban,
} from 'lucide-react';

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

function toDateInput(value) {
    if (!value) return '';
    return String(value).slice(0, 10);
}

export default function InvoiceShow({ invoice = {}, publicUrl = '', paidAmount = 0, balanceDue = 0 }) {
    const { t } = useTranslation();
    const { flash, errors = {} } = usePage().props;

    const [deleting, setDeleting] = useState(false);
    const [copied, setCopied] = useState(false);
    const [showPayment, setShowPayment] = useState(false);
    const [showEdit, setShowEdit] = useState(false);

    const paymentForm = useForm({
        amount: '',
        method: 'bank_transfer',
        reference: '',
        notes: '',
        paid_at: '',
    });

    const editForm = useForm({
        lead_id: invoice.lead_id ?? '',
        company_id: invoice.company_id ?? '',
        subtotal: invoice.subtotal ?? 0,
        tax_total: invoice.tax_total ?? 0,
        total: invoice.total ?? 0,
        currency: invoice.currency ?? '',
        due_date: toDateInput(invoice.due_date),
        notes: invoice.notes ?? '',
    });

    const openPayment = () => {
        paymentForm.setData({
            amount: balanceDue > 0 ? String(balanceDue) : '',
            method: 'bank_transfer',
            reference: '',
            notes: '',
            paid_at: '',
        });
        paymentForm.clearErrors();
        setShowPayment(true);
    };

    const submitPayment = (event) => {
        event.preventDefault();
        paymentForm.post(route('client.sales.invoices.payments.store', invoice.id), {
            preserveScroll: true,
            onSuccess: () => setShowPayment(false),
        });
    };

    const openEdit = () => {
        editForm.setData({
            lead_id: invoice.lead_id ?? '',
            company_id: invoice.company_id ?? '',
            subtotal: invoice.subtotal ?? 0,
            tax_total: invoice.tax_total ?? 0,
            total: invoice.total ?? 0,
            currency: invoice.currency ?? '',
            due_date: toDateInput(invoice.due_date),
            notes: invoice.notes ?? '',
        });
        editForm.clearErrors();
        setShowEdit(true);
    };

    const submitEdit = (event) => {
        event.preventDefault();
        editForm.put(route('client.sales.invoices.update', invoice.id), {
            preserveScroll: true,
            onSuccess: () => setShowEdit(false),
        });
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
        router.delete(route('client.sales.invoices.destroy', invoice.id), {
            onSuccess: () => setDeleting(false),
        });
    };

    const send = () => {
        router.post(route('client.sales.invoices.send', invoice.id), {}, { preserveScroll: true });
    };

    const items = invoice.quote?.items || [];
    const isPaid = invoice.status === 'paid';
    const canEdit = !isPaid && invoice.status !== 'cancelled';
    const canPay = !isPaid && invoice.status !== 'cancelled';

    return (
        <ClientLayout>
            <Head title={invoice.number || t('invoices.title')} />

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
                            href={route('client.sales.invoices.index')}
                            className="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">{invoice.number}</h1>
                                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusStyles[invoice.status] || statusStyles.draft}`}>
                                    {invoice.status === 'overdue' && <AlertTriangle className="h-3 w-3" />}
                                    {t(`invoices.status_${invoice.status}`)}
                                </span>
                            </div>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('invoices.billed_to')}: {invoice.company?.name || invoice.lead?.name || t('invoices.no_customer')}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <a href={route('client.sales.invoices.pdf', invoice.id)}>
                            <Button variant="secondary" className="inline-flex items-center gap-2">
                                <FileDown className="h-4 w-4" />
                                {t('invoices.pdf')}
                            </Button>
                        </a>

                        {canEdit && (
                            <Button variant="secondary" onClick={openEdit} className="inline-flex items-center gap-2">
                                <Pencil className="h-4 w-4" />
                                {t('common.edit')}
                            </Button>
                        )}

                        {canPay && (
                            <>
                                <Button variant="secondary" onClick={send} className="inline-flex items-center gap-2">
                                    <Send className="h-4 w-4" />
                                    {invoice.status === 'draft' ? t('invoices.send') : t('invoices.resend')}
                                </Button>
                                <Button onClick={openPayment} className="inline-flex items-center gap-2">
                                    <Plus className="h-4 w-4" />
                                    {t('invoices.record_payment')}
                                </Button>
                            </>
                        )}

                        {!isPaid && (
                            <button
                                type="button"
                                onClick={() => setDeleting(true)}
                                className="rounded-lg p-2 text-neutral-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                                title={t('common.delete')}
                            >
                                <Trash2 className="h-4 w-4" />
                            </button>
                        )}
                    </div>
                </div>

                <div className="grid gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        {items.length > 0 && (
                            <div className="overflow-hidden rounded-2xl border border-soft bg-white dark:bg-neutral-900">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-start text-sm">
                                        <thead>
                                            <tr className="border-b border-soft bg-neutral-50/75 text-xs font-medium text-neutral-500 dark:bg-neutral-800/50 dark:text-neutral-400">
                                                <th className="px-5 py-3.5 text-start">{t('quotes.item_description')}</th>
                                                <th className="px-4 py-3.5 text-end">{t('quotes.item_qty')}</th>
                                                <th className="px-4 py-3.5 text-end">{t('quotes.item_price')}</th>
                                                <th className="px-5 py-3.5 text-end">{t('quotes.total')}</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-soft">
                                            {items.map((item) => (
                                                <tr key={item.id}>
                                                    <td className="px-5 py-4 text-neutral-900 dark:text-neutral-100">{item.description}</td>
                                                    <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{Number(item.quantity)}</td>
                                                    <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{money(item.unit_price)}</td>
                                                    <td className="px-5 py-4 text-end tabular-nums font-medium text-neutral-900 dark:text-neutral-100">{money(item.total)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        <div className="overflow-hidden rounded-2xl border border-soft bg-white dark:bg-neutral-900">
                            <div className="flex items-center justify-between border-b border-soft px-5 py-3.5">
                                <h2 className="flex items-center gap-2 text-sm font-bold text-neutral-900 dark:text-neutral-100">
                                    <Wallet className="h-4 w-4 text-neutral-400" />
                                    {t('invoices.payments')}
                                </h2>
                            </div>

                            {(invoice.payments || []).length === 0 ? (
                                <p className="px-5 py-6 text-sm text-neutral-400">{t('invoices.no_payments')}</p>
                            ) : (
                                <table className="w-full text-start text-sm">
                                    <tbody className="divide-y divide-soft">
                                        {(invoice.payments || []).map((payment) => (
                                            <tr key={payment.id}>
                                                <td className="px-5 py-3 text-neutral-600 dark:text-neutral-300">{formatDate(payment.paid_at)}</td>
                                                <td className="px-4 py-3 capitalize text-neutral-500">{payment.method ? payment.method.replace('_', ' ') : '—'}</td>
                                                <td className="px-4 py-3 text-neutral-400">{payment.reference || ''}</td>
                                                <td className="px-5 py-3 text-end tabular-nums font-medium text-emerald-700 dark:text-emerald-400">
                                                    {money(payment.amount, invoice.currency)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>

                        {invoice.notes && (
                            <div className="rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                                <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('invoices.notes')}</h2>
                                <p className="mt-2 whitespace-pre-line text-sm text-neutral-700 dark:text-neutral-300">{invoice.notes}</p>
                            </div>
                        )}
                    </div>

                    <div className="space-y-5">
                        <div className="space-y-3 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('invoices.subtotal')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">{money(invoice.subtotal)}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('invoices.tax')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">{money(invoice.tax_total)}</dd>
                                </div>
                                <div className="flex justify-between border-t border-soft pt-2 text-base font-bold">
                                    <dt>{t('invoices.total')}</dt>
                                    <dd className="tabular-nums">{money(invoice.total, invoice.currency)}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-neutral-500">{t('invoices.paid')}</dt>
                                    <dd className="tabular-nums text-emerald-700 dark:text-emerald-400">{money(paidAmount)}</dd>
                                </div>
                                <div className="flex justify-between font-semibold">
                                    <dt>{t('invoices.balance_due')}</dt>
                                    <dd className="tabular-nums text-neutral-900 dark:text-neutral-100">{money(balanceDue, invoice.currency)}</dd>
                                </div>
                            </dl>

                            <div className="pt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                <div className="flex justify-between">
                                    <span>{t('invoices.due_date')}</span>
                                    <span className="text-neutral-700 dark:text-neutral-300">{formatDate(invoice.due_date)}</span>
                                </div>
                                {invoice.quote && (
                                    <div className="mt-1 flex justify-between">
                                        <span>{t('invoices.from_quote_label')}</span>
                                        <Link
                                            href={route('client.sales.quotes.show', invoice.quote.id)}
                                            className="text-brand-600 hover:underline dark:text-brand-400"
                                        >
                                            {invoice.quote.number}
                                        </Link>
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="space-y-2 rounded-2xl border border-soft bg-white p-5 dark:bg-neutral-900">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('invoices.public_link')}</h2>
                            <p className="text-[11px] text-neutral-400">{t('invoices.public_link_hint')}</p>
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

                        {invoice.status === 'overdue' && (
                            <div className="flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50/60 px-4 py-3 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                                <AlertTriangle className="h-4 w-4" />
                                {t('invoices.overdue_hint')}
                            </div>
                        )}

                        {isPaid && (
                            <div className="flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-xs text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
                                <CheckCircle2 className="h-4 w-4" />
                                {t('invoices.paid_hint')}
                            </div>
                        )}

                        {invoice.status === 'draft' && (
                            <div className="flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                                <Clock className="h-4 w-4" />
                                {t('invoices.draft_hint')}
                            </div>
                        )}

                        {invoice.status === 'cancelled' && (
                            <div className="flex items-center gap-2 rounded-2xl border border-neutral-200 bg-neutral-50/60 px-4 py-3 text-xs text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900/40 dark:text-neutral-400">
                                <Ban className="h-4 w-4" />
                                {t('invoices.cancelled_hint')}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={showPayment} onClose={() => setShowPayment(false)} maxWidth="md">
                <form onSubmit={submitPayment} className="space-y-4 p-6">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="flex items-center gap-2 text-lg font-bold text-neutral-900 dark:text-neutral-100">
                            <Wallet className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                            {t('invoices.record_payment')}
                        </h2>
                        <button type="button" onClick={() => setShowPayment(false)} className="text-neutral-400 hover:text-neutral-600">&times;</button>
                    </div>

                    <p className="rounded-lg bg-neutral-50 p-3 text-xs text-neutral-600 dark:bg-neutral-800/60 dark:text-neutral-300">
                        {t('invoices.balance_due')}: <span className="font-semibold">{money(balanceDue, invoice.currency)}</span>
                    </p>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.payment_amount')} *</label>
                            <input
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={paymentForm.data.amount}
                                onChange={(event) => paymentForm.setData('amount', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                            {paymentForm.errors.amount && <p className="mt-1 text-xs text-red-600">{paymentForm.errors.amount}</p>}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.payment_method')}</label>
                            <select
                                value={paymentForm.data.method}
                                onChange={(event) => paymentForm.setData('method', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            >
                                {['bank_transfer', 'cash', 'card', 'paypal', 'other'].map((value) => (
                                    <option key={value} value={value}>{t(`invoices.method_${value}`)}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.payment_reference')}</label>
                            <input
                                value={paymentForm.data.reference}
                                onChange={(event) => paymentForm.setData('reference', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.payment_date')}</label>
                            <input
                                type="date"
                                value={paymentForm.data.paid_at}
                                onChange={(event) => paymentForm.setData('paid_at', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.notes')}</label>
                            <textarea
                                rows={2}
                                value={paymentForm.data.notes}
                                onChange={(event) => paymentForm.setData('notes', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowPayment(false)}>{t('common.cancel')}</Button>
                        <Button type="submit" disabled={paymentForm.processing}>
                            {paymentForm.processing ? t('common.saving') : t('invoices.save_payment')}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal show={showEdit} onClose={() => setShowEdit(false)} maxWidth="lg">
                <form onSubmit={submitEdit} className="space-y-4 p-6">
                    <div className="flex items-center justify-between border-b border-soft pb-4">
                        <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">{t('invoices.edit')}</h2>
                        <button type="button" onClick={() => setShowEdit(false)} className="text-neutral-400 hover:text-neutral-600">&times;</button>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.subtotal')}</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={editForm.data.subtotal}
                                onChange={(event) => editForm.setData('subtotal', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.tax')}</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={editForm.data.tax_total}
                                onChange={(event) => editForm.setData('tax_total', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.total')}</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={editForm.data.total}
                                onChange={(event) => editForm.setData('total', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.currency')}</label>
                            <input
                                value={editForm.data.currency}
                                onChange={(event) => editForm.setData('currency', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.due_date')}</label>
                            <input
                                type="date"
                                value={editForm.data.due_date}
                                onChange={(event) => editForm.setData('due_date', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <label className="block text-xs font-semibold text-neutral-700 dark:text-neutral-300">{t('invoices.notes')}</label>
                            <textarea
                                rows={3}
                                value={editForm.data.notes}
                                onChange={(event) => editForm.setData('notes', event.target.value)}
                                className="mt-1 w-full rounded-lg border-soft bg-white p-2.5 text-sm dark:bg-neutral-800 dark:text-neutral-100"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-soft pt-4">
                        <Button type="button" variant="secondary" onClick={() => setShowEdit(false)}>{t('common.cancel')}</Button>
                        <Button type="submit" disabled={editForm.processing}>
                            {editForm.processing ? t('common.saving') : t('common.save')}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal show={deleting} onClose={() => setDeleting(false)} maxWidth="sm">
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-bold text-neutral-900 dark:text-neutral-100">{t('invoices.delete_title')}</h2>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('invoices.delete_body')}</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDeleting(false)}>{t('common.cancel')}</Button>
                        <Button variant="danger" onClick={remove}>{t('common.delete')}</Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}
