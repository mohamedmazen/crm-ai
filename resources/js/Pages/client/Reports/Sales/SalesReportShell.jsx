import ClientLayout from '@/Layouts/ClientLayout';
import { KpiCard } from '@/Components/Charts';
import { DatePicker } from '@/Components/ui';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Download } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const selectClass = 'rounded-soft border border-soft border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500/20';

function formatNumber(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function Filters({ routeName, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [pipelineId, setPipelineId] = useState(filters.pipeline_id ? String(filters.pipeline_id) : '');
    const [agentId, setAgentId] = useState(filters.agent_id ? String(filters.agent_id) : '');
    const [source, setSource] = useState(filters.source ?? '');

    const apply = () => {
        const params = {};

        if (from) params.from = from;
        if (to) params.to = to;
        if (pipelineId) params.pipeline_id = pipelineId;
        if (agentId) params.agent_id = agentId;
        if (source) params.source = source;

        router.get(route(routeName), params, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="flex flex-wrap items-end gap-2 rounded-xl border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
            <DatePicker value={from} onChange={setFrom} className="w-40" />
            <span className="pb-2 text-neutral-400">—</span>
            <DatePicker value={to} onChange={setTo} className="w-40" />

            <select value={pipelineId} onChange={(e) => setPipelineId(e.target.value)} className={selectClass} aria-label={t('reports.sales_pipeline')}>
                <option value="">{t('reports.sales_all_pipelines')}</option>
                {(pipelines ?? []).map((pipeline) => (
                    <option key={pipeline.id} value={pipeline.id}>{pipeline.name}</option>
                ))}
            </select>

            <select value={agentId} onChange={(e) => setAgentId(e.target.value)} className={selectClass} aria-label={t('reports.sales_agent')}>
                <option value="">{t('reports.sales_all_agents')}</option>
                {(agents ?? []).map((agent) => (
                    <option key={agent.id} value={agent.id}>{agent.name}</option>
                ))}
            </select>

            <select value={source} onChange={(e) => setSource(e.target.value)} className={selectClass} aria-label={t('reports.sales_source')}>
                <option value="">{t('reports.sales_all_sources')}</option>
                {(sources ?? []).map((label) => (
                    <option key={label} value={label}>{label}</option>
                ))}
            </select>

            <button
                type="button"
                onClick={apply}
                className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-500"
            >
                {t('reports.apply')}
            </button>
        </div>
    );
}

function SummaryCards({ summary, items }) {
    const { t } = useTranslation();

    const cards = items ?? [
        { label: t('reports.sales_total_leads'), value: summary?.leads ?? 0 },
        { label: t('reports.sales_won'), value: summary?.won ?? 0 },
        { label: t('reports.sales_lost'), value: summary?.lost ?? 0 },
        { label: t('reports.sales_conversion_rate'), value: `${summary?.conversion_rate ?? 0}%` },
        { label: t('reports.sales_won_value'), value: formatNumber(summary?.won_value ?? 0) },
    ];

    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
            {cards.map((card) => (
                <KpiCard key={card.label} label={card.label} value={card.value} />
            ))}
        </div>
    );
}

function ReportTabs({ active }) {
    const { t } = useTranslation();

    const tabs = [
        { key: 'volume', route: 'client.reports.sales.volume', label: t('reports.sales_tab_volume') },
        { key: 'sources', route: 'client.reports.sales.sources', label: t('reports.sales_tab_sources') },
        { key: 'pipeline', route: 'client.reports.sales.pipeline', label: t('reports.sales_tab_pipeline') },
        { key: 'win-loss', route: 'client.reports.sales.win-loss', label: t('reports.sales_tab_win_loss') },
        { key: 'agents', route: 'client.reports.sales.agents', label: t('reports.sales_tab_agents') },
        { key: 'response', route: 'client.reports.sales.response', label: t('reports.sales_tab_response') },
        { key: 'attribution', route: 'client.reports.sales.attribution', label: t('reports.sales_tab_attribution') },
    ];

    return (
        <div className="flex flex-wrap gap-2">
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={route(tab.route)}
                    className={[
                        'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        active === tab.key
                            ? 'bg-brand-600 text-white'
                            : 'border border-neutral-200 bg-white text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800',
                    ].join(' ')}
                >
                    {tab.label}
                </Link>
            ))}
        </div>
    );
}

/**
 * Shared chrome for the four sales reports: header, tabs, filters and KPI cards.
 */
export default function SalesReportShell({
    title,
    subtitle,
    active,
    routeName,
    exportReport,
    filters,
    pipelines,
    agents,
    sources,
    summary,
    summaryItems,
    children,
}) {
    const { t } = useTranslation();

    const exportParams = { report: exportReport };

    if (filters?.from) exportParams.from = filters.from;
    if (filters?.to) exportParams.to = filters.to;
    if (filters?.pipeline_id) exportParams.pipeline_id = filters.pipeline_id;
    if (filters?.agent_id) exportParams.agent_id = filters.agent_id;
    if (filters?.source) exportParams.source = filters.source;

    return (
        <ClientLayout title={title}>
            <Head title={title} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-bold text-neutral-900 dark:text-white">{title}</h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">{subtitle}</p>
                    </div>
                    <a
                        href={route('client.reports.sales.export', exportParams)}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm font-medium text-neutral-600 transition hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        <Download className="h-4 w-4" />
                        {t('reports.sales_export_csv')}
                    </a>
                    <Link
                        href={route('client.reports.sales.schedules')}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm font-medium text-neutral-600 transition hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        <CalendarClock className="h-4 w-4" />
                        {t('reports.sales_schedules_link')}
                    </Link>
                </div>

                <ReportTabs active={active} />
                <Filters routeName={routeName} filters={filters} pipelines={pipelines} agents={agents} sources={sources} />
                <SummaryCards summary={summary} items={summaryItems} />

                {children}
            </div>
        </ClientLayout>
    );
}
