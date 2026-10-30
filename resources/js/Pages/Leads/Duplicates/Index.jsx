import { useState } from 'react';
import { Head, router, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import MergeModal from './MergeModal';
import {
    Copy, GitMerge, Search, CheckCircle2, ShieldAlert,
    Phone, Mail, Globe, Calendar, User, DollarSign,
    CheckSquare, Clock, ArrowRight, Eye, RefreshCw
} from 'lucide-react';

export default function DuplicatesIndex({
    groups = { data: [], links: [], total: 0 },
    pendingCount = 0,
}) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const [scanning, setScanning] = useState(false);
    const [selectedGroup, setSelectedGroup] = useState(null);

    const handleScan = () => {
        setScanning(true);
        router.post(route('client.leads.duplicates.scan'), {}, {
            preserveScroll: true,
            onFinish: () => setScanning(false),
        });
    };

    const handleDismiss = (groupId) => {
        router.post(route('client.leads.duplicates.dismiss', groupId), {}, {
            preserveScroll: true,
        });
    };

    return (
        <ClientLayout>
            <Head title={t('duplicates.title') || 'Duplicate Leads'} />

            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                                <Copy className="h-5 w-5" />
                            </div>
                            <div>
                                <h1 className="text-xl font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                                    {t('duplicates.title') || 'Duplicate Detection & Merge'}
                                    {pendingCount > 0 && (
                                        <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                            {pendingCount} {t('duplicates.pending_groups') || 'groups'}
                                        </span>
                                    )}
                                </h1>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('duplicates.subtitle') || 'Detect and merge duplicate leads sharing matching phone numbers, email addresses, or domains.'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            onClick={handleScan}
                            disabled={scanning}
                            variant="secondary"
                            className="inline-flex items-center gap-2"
                        >
                            <RefreshCw className={`h-4 w-4 ${scanning ? 'animate-spin' : ''}`} />
                            {scanning ? (t('duplicates.scanning') || 'Scanning...') : (t('duplicates.scan_now') || 'Scan Workspace Now')}
                        </Button>
                    </div>
                </div>

                {/* Groups List */}
                {groups.data.length === 0 ? (
                    <div className="rounded-2xl border border-soft bg-white p-16 text-center shadow-xs dark:bg-neutral-900">
                        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                            <CheckCircle2 className="h-8 w-8" />
                        </div>
                        <h3 className="mt-4 text-base font-bold text-neutral-900 dark:text-neutral-100">
                            {t('duplicates.clean_database_title') || 'No Duplicates Detected!'}
                        </h3>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400 max-w-md mx-auto">
                            {t('duplicates.clean_database_subtitle') || 'Your CRM leads database is clean and deduplicated. You can run a full scan anytime new leads are imported.'}
                        </p>
                        <div className="mt-6">
                            <Button onClick={handleScan} disabled={scanning} size="sm">
                                <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${scanning ? 'animate-spin' : ''}`} />
                                {t('duplicates.scan_now') || 'Scan Workspace Now'}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {groups.data.map((group) => (
                            <div
                                key={group.id}
                                className="rounded-2xl border border-soft bg-white p-5 shadow-xs transition-shadow hover:shadow-md dark:bg-neutral-900 space-y-4"
                            >
                                {/* Group Top Bar */}
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-soft pb-3">
                                    <div className="flex items-center gap-2">
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200/50 dark:border-amber-800">
                                            {group.match_type === 'phone' ? <Phone className="h-3 w-3" /> : group.match_type === 'email' ? <Mail className="h-3 w-3" /> : <Globe className="h-3 w-3" />}
                                            <span className="capitalize">{group.match_type}</span>: <span className="font-mono">{group.match_value}</span>
                                        </span>
                                        <span className="text-xs text-neutral-400">
                                            {group.leads?.length ?? 0} {t('duplicates.potential_duplicates') || 'conflicting records'}
                                        </span>
                                    </div>

                                    <div className="flex items-center gap-2 self-end sm:self-auto">
                                        <button
                                            type="button"
                                            onClick={() => handleDismiss(group.id)}
                                            className="text-xs font-medium text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300 px-2 py-1"
                                        >
                                            {t('duplicates.dismiss') || 'Dismiss'}
                                        </button>
                                        <Button
                                            size="sm"
                                            onClick={() => setSelectedGroup(group)}
                                            className="inline-flex items-center gap-1.5"
                                        >
                                            <GitMerge className="h-3.5 w-3.5" />
                                            {t('duplicates.review_and_merge') || 'Review & Merge'}
                                        </Button>
                                    </div>
                                </div>

                                {/* Competing Leads Grid */}
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    {group.leads?.map((lead) => (
                                        <div
                                            key={lead.id}
                                            className="rounded-xl border border-soft bg-neutral-50/60 p-3.5 dark:bg-neutral-800/40 text-xs space-y-2"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <a
                                                        href={route('client.leads.pipeline.index', { lead: lead.id })}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="font-bold text-neutral-900 hover:text-brand-600 dark:text-neutral-100 dark:hover:text-brand-400 truncate block text-sm"
                                                    >
                                                        {lead.name}
                                                    </a>
                                                    {lead.company?.name && (
                                                        <span className="text-neutral-500 dark:text-neutral-400">
                                                            {lead.company.name}
                                                        </span>
                                                    )}
                                                </div>
                                                <span className="font-mono text-[10px] text-neutral-400 shrink-0">
                                                    #{lead.id}
                                                </span>
                                            </div>

                                            <div className="space-y-1 text-neutral-600 dark:text-neutral-300">
                                                {lead.phone && (
                                                    <div className="flex items-center gap-1.5 truncate">
                                                        <Phone className="h-3 w-3 text-neutral-400 shrink-0" />
                                                        <span>{lead.phone}</span>
                                                    </div>
                                                )}
                                                {lead.email && (
                                                    <div className="flex items-center gap-1.5 truncate">
                                                        <Mail className="h-3 w-3 text-neutral-400 shrink-0" />
                                                        <span>{lead.email}</span>
                                                    </div>
                                                )}
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2 border-t border-soft/60 pt-2 text-[11px] text-neutral-500">
                                                <span className="font-medium text-neutral-800 dark:text-neutral-200">
                                                    {lead.stage?.name || 'No Stage'}
                                                </span>
                                                <span>•</span>
                                                <span className="tabular-nums font-semibold text-neutral-900 dark:text-neutral-100">
                                                    ${Number(lead.deal_value || 0).toLocaleString()}
                                                </span>
                                                <span>•</span>
                                                <span>{lead.activities_count ?? 0} activities</span>
                                                <span>•</span>
                                                <span>{lead.tasks_count ?? 0} tasks</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}

                        {/* Pagination */}
                        {groups.links && groups.links.length > 3 && (
                            <div className="flex items-center justify-between border-t border-soft pt-4">
                                <div className="text-xs text-neutral-500">
                                    {t('common.total') || 'Total'}: <span className="font-semibold text-neutral-800 dark:text-neutral-200">{groups.total}</span>
                                </div>
                                <div className="flex items-center gap-1">
                                    {groups.links.map((link, i) => (
                                        link.url ? (
                                            <Link
                                                key={i}
                                                href={link.url}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                                className={`rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                                                    link.active
                                                        ? 'bg-brand-500 text-white'
                                                        : 'text-neutral-600 hover:bg-neutral-200/60 dark:text-neutral-300 dark:hover:bg-neutral-700'
                                                }`}
                                            />
                                        ) : (
                                            <span
                                                key={i}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                                className="px-2 py-1 text-xs text-neutral-400 cursor-not-allowed"
                                            />
                                        )
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Merge Modal */}
            <MergeModal
                group={selectedGroup}
                show={!!selectedGroup}
                onClose={() => setSelectedGroup(null)}
            />
        </ClientLayout>
    );
}

