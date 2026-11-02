import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import DataTable from '@/Components/DataTable';
import {
    Plus,
    Play,
    Pause,
    Trash2,
    BarChart2,
    Pencil,
    Radio,
} from 'lucide-react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { browserTz, formatInTz } from '@/Utils/datetime';
import { ChannelBrandIcon, CHANNEL_LABELS } from '@/Components/BrandIcons';
import useInertiaLoading from '@/hooks/useInertiaLoading';

const STATUS_COLORS = {
    draft:     'bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300',
    queued:    'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    sending:   'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    paused:    'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    failed:    'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

const STATUS_LABEL_KEYS = {
    draft: 'campaign.status_draft',
    queued: 'campaign.status_queued',
    sending: 'campaign.status_sending',
    paused: 'campaign.status_paused',
    completed: 'campaign.status_completed',
    failed: 'campaign.status_failed',
};

export default function CampaignsIndex({ campaigns, filters }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const userTz = props.timezone || browserTz() || 'Asia/Dhaka';
    const loading = useInertiaLoading();

    const handleFilter = (key, val) =>
        router.get(
            route('client.campaigns.index'),
            { ...filters, [key]: val },
            { preserveState: true, replace: true },
        );
    const handleLaunch = (id) =>
        router.post(route('client.campaigns.launch', id), {}, { preserveScroll: true });
    const handlePause = (id) =>
        router.post(route('client.campaigns.pause', id), {}, { preserveScroll: true });
    const handleDelete = (id) => {
        if (confirm(t('campaign.delete_confirm'))) {
            router.delete(route('client.campaigns.destroy', id), { preserveScroll: true });
        }
    };

    // Auto-refresh while any campaign is actively sending so totals tick up.
    const liveCount = campaigns.data.filter((c) => ['queued', 'sending'].includes(c.status)).length;
    useEffect(() => {
        if (liveCount === 0) return;
        const id = setInterval(() => {
            router.reload({ only: ['campaigns'], preserveScroll: true });
        }, 10000);
        return () => clearInterval(id);
    }, [liveCount]);

    const columns = [
        {
            key: 'name',
            header: t('campaign.col_campaign'),
            sortable: true,
            cardTitle: true,
            render: (c) => (
                <Link href={route('client.campaigns.show', c.uuid)} className="font-medium text-neutral-900 hover:text-brand-600 dark:text-neutral-100">
                    {c.name}
                </Link>
            ),
        },
        {
            key: 'channel',
            header: t('campaign.col_channel'),
            render: (c) => (
                <span className="inline-flex items-center gap-1.5">
                    <ChannelBrandIcon channel={c.channel} className="h-4 w-4 shrink-0" />
                    {CHANNEL_LABELS[c.channel] ?? c.channel}
                </span>
            ),
        },
        {
            key: 'status',
            header: t('campaign.col_status'),
            sortable: true,
            render: (c) => {
                const live = ['queued', 'sending'].includes(c.status);
                return (
                    <span className="inline-flex items-center">
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[c.status] ?? ''}`}>
                            {STATUS_LABEL_KEYS[c.status] ? t(STATUS_LABEL_KEYS[c.status]) : c.status}
                        </span>
                        {live && (
                            <span className="ms-2 inline-flex items-center gap-1 text-xs text-yellow-700 dark:text-yellow-300">
                                <span className="relative flex h-2 w-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-yellow-400 opacity-75" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-yellow-500" />
                                </span>
                                {t('campaign.live')}
                            </span>
                        )}
                    </span>
                );
            },
        },
        {
            key: 'recipients',
            header: t('campaign.col_recipients'),
            render: (c) => <span className="text-neutral-600 dark:text-neutral-400">{c.totals_json?.total ?? 0}</span>,
        },
        {
            key: 'delivered',
            header: t('campaign.col_delivered'),
            render: (c) => {
                const totals = c.totals_json ?? {};
                const deliveredPct = totals.total > 0 ? Math.round((totals.delivered / totals.total) * 100) : 0;
                return <span className="text-neutral-600 dark:text-neutral-400">{totals.delivered ?? 0} ({deliveredPct}%)</span>;
            },
        },
        {
            key: 'schedule_at',
            header: t('campaign.col_scheduled'),
            sortable: true,
            render: (c) => (
                <span className="text-xs text-neutral-500 dark:text-neutral-400">
                    {c.schedule_at ? <span title={c.timezone || userTz}>{formatInTz(c.schedule_at, c.timezone || userTz)}</span> : '—'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: t('common.actions', { defaultValue: 'Actions' }),
            align: 'right',
            hideInCard: false,
            render: (c) => {
                const canEdit = ['draft', 'paused'].includes(c.status);
                return (
                    <div className="flex items-center justify-end gap-1">
                        <Link
                            href={route('client.reports.campaigns.show', c.uuid)}
                            title={t('campaign.view_full_report')}
                            className="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 transition hover:text-brand-600 dark:text-neutral-400"
                        >
                            <BarChart2 className="h-4 w-4" />
                        </Link>
                        {canEdit && (
                            <Link
                                href={route('client.campaigns.edit', c.uuid)}
                                title={t('common.edit')}
                                className="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 transition hover:text-brand-600 dark:text-neutral-400"
                            >
                                <Pencil className="h-4 w-4" />
                            </Link>
                        )}
                        {c.status === 'draft' && (
                            <button
                                type="button"
                                onClick={() => handleLaunch(c.uuid)}
                                title={t('campaign.launch')}
                                className="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 transition hover:text-green-500 dark:text-neutral-400"
                            >
                                <Play className="h-4 w-4" />
                            </button>
                        )}
                        {c.status === 'sending' && (
                            <button
                                type="button"
                                onClick={() => handlePause(c.uuid)}
                                title={t('campaign.pause')}
                                className="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 transition hover:text-orange-500 dark:text-neutral-400"
                            >
                                <Pause className="h-4 w-4" />
                            </button>
                        )}
                        {c.status === 'paused' && (
                            <button
                                type="button"
                                onClick={() => handleLaunch(c.uuid)}
                                title={t('campaign.resume')}
                                className="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 transition hover:text-green-500 dark:text-neutral-400"
                            >
                                <Play className="h-4 w-4" />
                            </button>
                        )}
                        {c.status === 'draft' && (
                            <button
                                type="button"
                                onClick={() => handleDelete(c.uuid)}
                                title={t('common.delete')}
                                className="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 transition hover:text-red-500 dark:text-neutral-400"
                            >
                                <Trash2 className="h-4 w-4" />
                            </button>
                        )}
                    </div>
                );
            },
        },
    ];

    const toolbar = (
        <div className="flex flex-wrap gap-2">
            <select
                value={filters.channel ?? ''}
                onChange={(e) => handleFilter('channel', e.target.value || null)}
                aria-label={t('campaign.filter_channel', { defaultValue: 'Filter by channel' })}
                className="rounded-soft border border-neutral-300 bg-white px-3 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800"
            >
                <option value="">{t('campaign.all_channels')}</option>
                {['whatsapp', 'sms', 'email'].map((c) => (
                    <option key={c} value={c}>
                        {CHANNEL_LABELS[c] ?? c}
                    </option>
                ))}
            </select>
            <select
                value={filters.status ?? ''}
                onChange={(e) => handleFilter('status', e.target.value || null)}
                aria-label={t('campaign.filter_status', { defaultValue: 'Filter by status' })}
                className="rounded-soft border border-neutral-300 bg-white px-3 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800"
            >
                <option value="">{t('campaign.all_statuses')}</option>
                {['draft', 'queued', 'sending', 'paused', 'completed', 'failed'].map((s) => (
                    <option key={s} value={s}>
                        {t(STATUS_LABEL_KEYS[s])}
                    </option>
                ))}
            </select>
        </div>
    );

    return (
        <ClientLayout title={t('campaign.title')}>
            <Head title={t('campaign.head_title')} />
            <div className="space-y-5">
                <PageHeader
                    title={t('campaign.title')}
                    actions={
                        <Link
                            href={route('client.campaigns.create')}
                            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                        >
                            <Plus className="h-4 w-4" /> {t('campaign.new_campaign')}
                        </Link>
                    }
                />

                {flash.success && (
                    <div className="rounded-lg bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <DataTable
                    columns={columns}
                    rows={campaigns.data}
                    pagination={campaigns}
                    storageKey="campaigns"
                    loading={loading}
                    toolbar={toolbar}
                    emptyState={
                        <EmptyState
                            icon={<Radio className="h-8 w-8" />}
                            title={t('campaign.empty_title')}
                            description={t('campaign.empty_desc')}
                            action={{
                                label: t('campaign.new_campaign'),
                                href: route('client.campaigns.create'),
                            }}
                        />
                    }
                />
            </div>
        </ClientLayout>
    );
}
