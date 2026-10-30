import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import Modal from '@/Components/ui/Modal';
import {
    BarChart2, ArrowLeft, Eye, CheckCircle2, TrendingUp,
    ExternalLink, Calendar, User, Globe, Code, Sliders
} from 'lucide-react';

export default function FormAnalytics({
    form,
    submissions = { data: [], links: [], total: 0 },
    utmSources = [],
}) {
    const { t } = useTranslation();
    const [selectedPayload, setSelectedPayload] = useState(null);

    return (
        <ClientLayout>
            <Head title={`${form.name} - ${t('forms.analytics') || 'Analytics & Submissions'}`} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-soft pb-4">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('client.sales.forms.index')}
                            className="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <div>
                            <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                                {form.name}
                            </h1>
                            <p className="text-xs text-neutral-400 font-mono">
                                {form.public_url}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href={route('client.sales.forms.builder', form.id)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-soft bg-white px-3 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <Sliders className="h-3.5 w-3.5" />
                            {t('forms.builder') || 'Open Builder'}
                        </Link>
                        <a
                            href={form.public_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-soft bg-white px-3 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            <ExternalLink className="h-3.5 w-3.5" />
                            {t('forms.view_public') || 'Open Form'}
                        </a>
                    </div>
                </div>

                {/* KPI Metrics */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="rounded-2xl border border-soft bg-white p-5 shadow-xs dark:bg-neutral-900">
                        <span className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 flex items-center gap-1.5">
                            <Eye className="h-4 w-4 text-blue-500" />
                            {t('forms.total_views') || 'Total Page Views'}
                        </span>
                        <div className="mt-2 text-3xl font-bold text-neutral-900 dark:text-neutral-100">
                            {form.views_count.toLocaleString()}
                        </div>
                    </div>

                    <div className="rounded-2xl border border-soft bg-white p-5 shadow-xs dark:bg-neutral-900">
                        <span className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 flex items-center gap-1.5">
                            <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                            {t('forms.total_submissions') || 'Total Inbound Submissions'}
                        </span>
                        <div className="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400">
                            {form.submissions_count.toLocaleString()}
                        </div>
                    </div>

                    <div className="rounded-2xl border border-soft bg-white p-5 shadow-xs dark:bg-neutral-900">
                        <span className="text-xs font-semibold text-neutral-500 dark:text-neutral-400 flex items-center gap-1.5">
                            <TrendingUp className="h-4 w-4 text-brand-500" />
                            {t('forms.conversion_rate') || 'Conversion Rate'}
                        </span>
                        <div className="mt-2 text-3xl font-bold text-brand-600 dark:text-brand-400">
                            {form.conversion_rate}%
                        </div>
                    </div>
                </div>

                {/* UTM Attribution Summary */}
                {utmSources.length > 0 && (
                    <div className="rounded-2xl border border-soft bg-white p-5 shadow-xs dark:bg-neutral-900 space-y-3">
                        <h3 className="text-sm font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <Globe className="h-4 w-4 text-neutral-400" />
                            {t('forms.utm_sources') || 'Marketing Source Attribution (UTM)'}
                        </h3>
                        <div className="flex flex-wrap gap-2">
                            {utmSources.map((s, i) => (
                                <span
                                    key={i}
                                    className="inline-flex items-center gap-2 rounded-xl border border-soft bg-neutral-50 px-3 py-1.5 text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    <span className="font-semibold">{s.utm_source}</span>
                                    <span className="rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-bold text-brand-700 dark:bg-brand-950 dark:text-brand-300">
                                        {s.count}
                                    </span>
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {/* Submissions List */}
                <div className="overflow-hidden rounded-2xl border border-soft bg-white shadow-xs dark:bg-neutral-900">
                    <div className="border-b border-soft px-5 py-4">
                        <h2 className="text-sm font-bold text-neutral-900 dark:text-neutral-100">
                            {t('forms.recent_submissions') || 'Submission Entries'} ({submissions.total})
                        </h2>
                    </div>

                    {submissions.data.length === 0 ? (
                        <div className="p-12 text-center text-sm text-neutral-400">
                            {t('forms.no_submissions_yet') || 'No submissions received yet for this form.'}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <thead>
                                    <tr className="border-b border-soft bg-neutral-50/75 dark:bg-neutral-800/50 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                        <th className="px-5 py-3 text-start">{t('common.date') || 'Date'}</th>
                                        <th className="px-4 py-3 text-start">{t('leads.title') || 'Captured Lead'}</th>
                                        <th className="px-4 py-3 text-start">{t('forms.traffic_source') || 'Source / UTM'}</th>
                                        <th className="px-4 py-3 text-start">{t('forms.ip_address') || 'IP Address'}</th>
                                        <th className="px-5 py-3 text-end">{t('forms.payload') || 'Form Data'}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-soft">
                                    {submissions.data.map((sub) => (
                                        <tr key={sub.id} className="transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-3.5 text-xs text-neutral-500 dark:text-neutral-400">
                                                {new Date(sub.created_at).toLocaleString()}
                                            </td>

                                            <td className="px-4 py-3.5">
                                                {sub.lead ? (
                                                    <a
                                                        href={route('client.leads.pipeline.index', { lead: sub.lead.id })}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="font-semibold text-brand-600 hover:underline dark:text-brand-400 text-xs flex items-center gap-1"
                                                    >
                                                        {sub.lead.name}
                                                        <ExternalLink className="h-3 w-3" />
                                                    </a>
                                                ) : (
                                                    <span className="text-xs text-neutral-400 italic">No Lead</span>
                                                )}
                                            </td>

                                            <td className="px-4 py-3.5 text-xs text-neutral-600 dark:text-neutral-300">
                                                {sub.utm_source ? (
                                                    <span className="font-mono text-[11px] text-brand-600 dark:text-brand-400">
                                                        {sub.utm_source} {sub.utm_campaign && `(${sub.utm_campaign})`}
                                                    </span>
                                                ) : sub.referrer ? (
                                                    <span className="truncate max-w-[150px] block text-[11px] text-neutral-400">
                                                        {sub.referrer}
                                                    </span>
                                                ) : (
                                                    <span className="text-neutral-400 italic">Direct / Embed</span>
                                                )}
                                            </td>

                                            <td className="px-4 py-3.5 font-mono text-xs text-neutral-400">
                                                {sub.ip || '—'}
                                            </td>

                                            <td className="px-5 py-3.5 text-end">
                                                <Button
                                                    size="xs"
                                                    variant="secondary"
                                                    onClick={() => setSelectedPayload(sub.payload)}
                                                >
                                                    <Code className="h-3 w-3 mr-1" />
                                                    {t('forms.view_data') || 'View Answers'}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* Payload Inspection Modal */}
            <Modal show={!!selectedPayload} onClose={() => setSelectedPayload(null)} maxWidth="md">
                <div className="p-6 space-y-4">
                    <div className="flex items-center justify-between border-b border-soft pb-3">
                        <h3 className="text-sm font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <Code className="h-4 w-4 text-brand-600" />
                            {t('forms.submission_data') || 'Submitted Answers'}
                        </h3>
                        <button
                            type="button"
                            onClick={() => setSelectedPayload(null)}
                            className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                        >
                            &times;
                        </button>
                    </div>

                    {selectedPayload && (
                        <div className="rounded-xl border border-soft divide-y divide-soft bg-neutral-50/60 dark:bg-neutral-800/40 text-xs">
                            {Object.entries(selectedPayload).map(([key, val]) => (
                                <div key={key} className="p-2.5 flex items-start justify-between gap-4">
                                    <span className="font-semibold text-neutral-600 dark:text-neutral-300 capitalize">
                                        {key.replace(/_/g, ' ')}:
                                    </span>
                                    <span className="font-medium text-neutral-900 dark:text-neutral-100 text-end">
                                        {Array.isArray(val) ? val.join(', ') : typeof val === 'object' ? JSON.stringify(val) : String(val)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="flex justify-end pt-2">
                        <Button variant="secondary" onClick={() => setSelectedPayload(null)}>
                            {t('common.close') || 'Close'}
                        </Button>
                    </div>
                </div>
            </Modal>
        </ClientLayout>
    );
}

