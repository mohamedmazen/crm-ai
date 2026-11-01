import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { CheckCircle2, FileDown, AlertTriangle, ShieldCheck, Wallet } from 'lucide-react';

const statusStyles = {
    draft: 'bg-neutral-100 text-neutral-600',
    sent: 'bg-amber-100 text-amber-800',
    viewed: 'bg-sky-100 text-sky-800',
    paid: 'bg-emerald-100 text-emerald-800',
    overdue: 'bg-red-100 text-red-800',
    cancelled: 'bg-neutral-100 text-neutral-500',
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

export default function PublicInvoice({ invoice = {}, pdfUrl = '', paidAmount = 0, balanceDue = 0 }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const items = invoice.quote?.items || [];
    const payments = invoice.payments || [];
    const isPaid = invoice.status === 'paid';

    return (
        <div className="min-h-screen bg-neutral-100 px-4 py-10 text-neutral-800 dark:bg-neutral-950 dark:text-neutral-200">
            <Head title={invoice.number} />

            <div className="mx-auto w-full max-w-3xl space-y-4">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <CheckCircle2 className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}

                <div className="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-4 border-b border-neutral-100 p-6 dark:border-neutral-800 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="text-sm font-semibold text-brand-600 dark:text-brand-400">
                                {invoice.workspace?.name || ''}
                            </div>
                            <h1 className="mt-1 text-2xl font-bold">{invoice.number}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('invoices.billed_to')}: {invoice.company?.name || invoice.lead?.name || t('invoices.no_customer')}
                            </p>
                        </div>

                        <div className="text-start sm:text-end">
                            <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statusStyles[invoice.status] || statusStyles.draft}`}>
                                {invoice.status === 'overdue' && <AlertTriangle className="h-3 w-3" />}
                                {t(`invoices.status_${invoice.status}`)}
                            </span>
                            <div className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                                {t('invoices.due_date')}: {formatDate(invoice.due_date)}
                            </div>
                        </div>
                    </div>

                    {items.length > 0 && (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-neutral-100 bg-neutral-50/70 text-xs font-medium text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/50">
                                        <th className="px-6 py-3 text-start">{t('quotes.item_description')}</th>
                                        <th className="px-4 py-3 text-end">{t('quotes.item_qty')}</th>
                                        <th className="px-4 py-3 text-end">{t('quotes.item_price')}</th>
                                        <th className="px-6 py-3 text-end">{t('quotes.total')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                    {items.map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-6 py-4">{item.description}</td>
                                            <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{lineQuantity(item.quantity)}</td>
                                            <td className="px-4 py-4 text-end tabular-nums text-neutral-600 dark:text-neutral-300">{money(item.unit_price)}</td>
                                            <td className="px-6 py-4 text-end tabular-nums font-medium">{money(item.total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex justify-end border-t border-neutral-100 p-6 dark:border-neutral-800">
                        <dl className="w-full max-w-xs space-y-2 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-neutral-500">{t('invoices.subtotal')}</dt>
                                <dd className="tabular-nums">{money(invoice.subtotal)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-neutral-500">{t('invoices.tax')}</dt>
                                <dd className="tabular-nums">{money(invoice.tax_total)}</dd>
                            </div>
                            <div className="flex justify-between border-t border-neutral-200 pt-2 text-lg font-bold dark:border-neutral-700">
                                <dt>{t('invoices.total')}</dt>
                                <dd className="tabular-nums">{money(invoice.total, invoice.currency)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-neutral-500">{t('invoices.paid')}</dt>
                                <dd className="tabular-nums text-emerald-700 dark:text-emerald-400">{money(paidAmount)}</dd>
                            </div>
                            <div className="flex justify-between font-semibold">
                                <dt>{t('invoices.balance_due')}</dt>
                                <dd className="tabular-nums">{money(balanceDue, invoice.currency)}</dd>
                            </div>
                        </dl>
                    </div>

                    {payments.length > 0 && (
                        <div className="border-t border-neutral-100 p-6 dark:border-neutral-800">
                            <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                <Wallet className="h-3.5 w-3.5" />
                                {t('invoices.payments')}
                            </h2>
                            <ul className="mt-3 space-y-2 text-sm">
                                {payments.map((payment) => (
                                    <li key={payment.id} className="flex items-center justify-between">
                                        <span className="text-neutral-600 dark:text-neutral-300">
                                            {formatDate(payment.paid_at)}
                                            {payment.method ? ` · ${payment.method.replace('_', ' ')}` : ''}
                                        </span>
                                        <span className="tabular-nums text-emerald-700 dark:text-emerald-400">{money(payment.amount, invoice.currency)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {invoice.notes && (
                        <div className="border-t border-neutral-100 p-6 text-sm dark:border-neutral-800">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('invoices.notes')}</h2>
                            <p className="mt-1 whitespace-pre-line text-neutral-600 dark:text-neutral-300">{invoice.notes}</p>
                        </div>
                    )}

                    <div className="flex flex-col gap-3 border-t border-neutral-100 bg-neutral-50/60 p-6 dark:border-neutral-800 dark:bg-neutral-900/60 sm:flex-row sm:items-center sm:justify-between">
                        <a
                            href={pdfUrl}
                            className="inline-flex items-center gap-2 text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-300 dark:hover:text-white"
                        >
                            <FileDown className="h-4 w-4" />
                            {t('invoices.download_pdf')}
                        </a>

                        {isPaid ? (
                            <div className="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                <CheckCircle2 className="h-4 w-4" />
                                {t('invoices.paid_hint')}
                            </div>
                        ) : (
                            <div className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('invoices.public_pay_hint')}
                            </div>
                        )}
                    </div>
                </div>

                <p className="flex items-center justify-center gap-1.5 text-center text-xs text-neutral-400">
                    <ShieldCheck className="h-3.5 w-3.5" />
                    {t('invoices.public_footer', { app: invoice.workspace?.name || '' })}
                </p>
            </div>
        </div>
    );
}
