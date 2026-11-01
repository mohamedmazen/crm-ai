import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Card } from '@/Components/ui';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ShieldCheck, FileDown, UserX, Clock, CheckCircle, AlertCircle, Database } from 'lucide-react';

const STATUS_STYLES = {
    pending: 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300',
    completed: 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300',
    cancelled: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

function StatusBadge({ status }) {
    const { t } = useTranslation();
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_STYLES[status] ?? STATUS_STYLES.pending}`}>
            {status === 'pending' && <Clock className="h-3 w-3" />}
            {status === 'completed' && <CheckCircle className="h-3 w-3" />}
            {t(`gdpr.status.${status}`) || status}
        </span>
    );
}

export default function GdprIndex({ requests = [], retentionDays = 7, graceDays = 30 }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [cancelling, setCancelling] = useState(null);

    const erasureForm = useForm({ email: '', notes: '' });
    const retentionForm = useForm({ retention_days: retentionDays });

    const submitErasure = (e) => {
        e.preventDefault();
        erasureForm.post(route('client.settings.gdpr.erasure.store'), {
            preserveScroll: true,
            onSuccess: () => erasureForm.reset(),
        });
    };

    const cancelRequest = (id) => {
        if (!confirm(t('gdpr.cancel_confirm') || 'Cancel this erasure request? The data will be kept.')) return;
        setCancelling(id);
        router.delete(route('client.settings.gdpr.erasure.cancel', id), {
            preserveScroll: true,
            onFinish: () => setCancelling(null),
        });
    };

    const saveRetention = (e) => {
        e.preventDefault();
        retentionForm.put(route('client.settings.gdpr.retention'), { preserveScroll: true });
    };

    return (
        <ClientLayout title={t('gdpr.title') || 'Privacy & GDPR'}>
            <Head title={t('gdpr.title') || 'Privacy & GDPR'} />
            <div className="max-w-3xl space-y-6">
                <div>
                    <h2 className="flex items-center gap-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        <ShieldCheck className="h-5 w-5 text-brand-600" />
                        {t('gdpr.title') || 'Privacy & GDPR'}
                    </h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('gdpr.subtitle') || 'Manage data-subject requests, your data processing agreement and retention.'}
                    </p>
                </div>

                {flash.success && (
                    <div className="flex items-center gap-2 rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2.5 text-sm">
                        <CheckCircle className="h-4 w-4 shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2.5 text-sm">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        {flash.error}
                    </div>
                )}

                <Card>
                    <Card.Body className="space-y-3">
                        <div className="flex items-start gap-3">
                            <FileDown className="mt-0.5 h-5 w-5 text-brand-600" />
                            <div className="flex-1">
                                <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">
                                    {t('gdpr.dpa_title') || 'Data Processing Agreement'}
                                </h3>
                                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('gdpr.dpa_desc') || 'Download the DPA between your organisation and the platform, pre-filled with your details.'}
                                </p>
                            </div>
                            <a
                                href={route('client.settings.gdpr.dpa')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 px-3 py-2 text-sm font-medium text-white transition"
                            >
                                <FileDown className="h-4 w-4" />
                                {t('gdpr.download_pdf') || 'Download PDF'}
                            </a>
                        </div>
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="flex items-start gap-3">
                            <UserX className="mt-0.5 h-5 w-5 text-brand-600" />
                            <div>
                                <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">
                                    {t('gdpr.erasure_title') || 'Right to erasure'}
                                </h3>
                                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('gdpr.erasure_desc', { days: graceDays }) || `Requests are executed automatically after ${graceDays} days. Inbox conversations and contacts are deleted; leads are anonymised so financial records stay intact.`}
                                </p>
                            </div>
                        </div>

                        <form onSubmit={submitErasure} className="space-y-3">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                        {t('gdpr.email') || 'Email address of the data subject'}
                                    </label>
                                    <input
                                        type="email"
                                        required
                                        value={erasureForm.data.email}
                                        onChange={(e) => erasureForm.setData('email', e.target.value)}
                                        placeholder="customer@example.com"
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                    />
                                    {erasureForm.errors.email && <p className="text-xs text-red-500">{erasureForm.errors.email}</p>}
                                </div>
                                <div className="space-y-1">
                                    <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                        {t('gdpr.notes') || 'Notes (optional)'}
                                    </label>
                                    <input
                                        type="text"
                                        value={erasureForm.data.notes}
                                        onChange={(e) => erasureForm.setData('notes', e.target.value)}
                                        placeholder={t('gdpr.notes_placeholder') || 'Reference or reason'}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                    />
                                    {erasureForm.errors.notes && <p className="text-xs text-red-500">{erasureForm.errors.notes}</p>}
                                </div>
                            </div>
                            <button
                                type="submit"
                                disabled={erasureForm.processing}
                                className="rounded-lg bg-neutral-900 dark:bg-neutral-700 hover:bg-neutral-700 dark:hover:bg-neutral-600 disabled:opacity-60 px-4 py-2 text-sm font-medium text-white transition"
                            >
                                {erasureForm.processing ? (t('gdpr.saving') || 'Saving…') : (t('gdpr.create_request') || 'Create erasure request')}
                            </button>
                        </form>

                        {requests.length === 0 ? (
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                {t('gdpr.no_requests') || 'No erasure requests yet.'}
                            </p>
                        ) : (
                            <div className="overflow-x-auto pt-2">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-neutral-200 dark:border-neutral-800 text-left text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                            <th className="py-2 pr-4">{t('gdpr.email') || 'Email'}</th>
                                            <th className="py-2 pr-4">{t('gdpr.status_label') || 'Status'}</th>
                                            <th className="py-2 pr-4">{t('gdpr.due') || 'Scheduled'}</th>
                                            <th className="py-2 text-right">{t('common.actions') || 'Actions'}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {requests.map((request) => (
                                            <tr key={request.id} className="border-b border-neutral-100 dark:border-neutral-800/60 last:border-0">
                                                <td className="py-3 pr-4 text-neutral-700 dark:text-neutral-300 break-all">{request.email}</td>
                                                <td className="py-3 pr-4"><StatusBadge status={request.status} /></td>
                                                <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-400 whitespace-nowrap">
                                                    {request.status === 'pending'
                                                        ? `${request.days_remaining} ${t('gdpr.days_left') || 'days left'}`
                                                        : new Date(request.completed_at || request.cancelled_at || request.due_at).toLocaleDateString()}
                                                </td>
                                                <td className="py-3 text-right">
                                                    {request.status === 'pending' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => cancelRequest(request.id)}
                                                            disabled={cancelling === request.id}
                                                            className="text-xs font-medium text-red-600 hover:underline disabled:opacity-60"
                                                        >
                                                            {t('gdpr.cancel') || 'Cancel request'}
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Body className="space-y-4">
                        <div className="flex items-start gap-3">
                            <Database className="mt-0.5 h-5 w-5 text-brand-600" />
                            <div>
                                <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">
                                    {t('gdpr.retention_title') || 'Data retention'}
                                </h3>
                                <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('gdpr.retention_desc') || 'Generated export archives older than this are deleted automatically. Set 0 to keep them indefinitely.'}
                                </p>
                            </div>
                        </div>
                        <form onSubmit={saveRetention} className="flex flex-wrap items-end gap-3">
                            <div className="space-y-1">
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                    {t('gdpr.retention_days') || 'Keep export archives for (days)'}
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    max="365"
                                    value={retentionForm.data.retention_days}
                                    onChange={(e) => retentionForm.setData('retention_days', e.target.value)}
                                    className="w-40 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                                {retentionForm.errors.retention_days && <p className="text-xs text-red-500">{retentionForm.errors.retention_days}</p>}
                            </div>
                            <button
                                type="submit"
                                disabled={retentionForm.processing}
                                className="rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-60 px-4 py-2 text-sm font-medium text-white transition"
                            >
                                {retentionForm.processing ? (t('gdpr.saving') || 'Saving…') : (t('gdpr.save') || 'Save')}
                            </button>
                        </form>
                    </Card.Body>
                </Card>
            </div>
        </ClientLayout>
    );
}
