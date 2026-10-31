import { BarChart, LineChart } from '@/Components/Charts';
import { useTranslation } from 'react-i18next';
import SalesReportShell from './SalesReportShell';

function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    return `${(seconds / 3600).toFixed(1)}h`;
}

const BUCKET_KEYS = {
    under_5m: 'reports.sales_rt_under_5m',
    '5m_30m': 'reports.sales_rt_5m_30m',
    '30m_2h': 'reports.sales_rt_30m_2h',
    '2h_24h': 'reports.sales_rt_2h_24h',
    over_24h: 'reports.sales_rt_over_24h',
};

export default function Response({ rows, summary, responseSummary, distribution, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();

    const summaryItems = [
        { label: t('reports.sales_rt_responses'), value: responseSummary?.count ?? 0 },
        { label: t('reports.sales_rt_median'), value: formatDuration(responseSummary?.median_seconds) },
        { label: t('reports.sales_rt_p90'), value: formatDuration(responseSummary?.p90_seconds) },
        { label: t('reports.sales_rt_average'), value: formatDuration(responseSummary?.avg_seconds) },
        { label: t('reports.sales_rt_buckets'), value: (distribution ?? []).reduce((sum, item) => sum + item.count, 0) },
    ];

    const daily = (rows ?? []).map((row) => ({
        date: row.date,
        median: row.median_seconds === null ? null : Number((row.median_seconds / 60).toFixed(1)),
        p90: row.p90_seconds === null ? null : Number((row.p90_seconds / 60).toFixed(1)),
    }));

    const distributionData = (distribution ?? []).map((item) => ({
        bucket: t(BUCKET_KEYS[item.bucket] ?? 'reports.sales_rt_buckets'),
        count: item.count,
    }));

    const hasResponses = (responseSummary?.count ?? 0) > 0;

    return (
        <SalesReportShell
            title={t('reports.sales_response_title')}
            subtitle={t('reports.sales_response_subtitle')}
            active="response"
            routeName="client.reports.sales.response"
            exportReport="response"
            filters={filters}
            pipelines={pipelines}
            agents={agents}
            sources={sources}
            summary={summary}
            summaryItems={summaryItems}
        >
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900 lg:col-span-2">
                    <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                        {t('reports.sales_rt_trend')}
                    </h3>
                    {hasResponses ? (
                        <LineChart
                            data={daily}
                            xKey="date"
                            yKeys={['median', 'p90']}
                            labels={{
                                median: t('reports.sales_rt_median'),
                                p90: t('reports.sales_rt_p90'),
                            }}
                            height={300}
                        />
                    ) : (
                        <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
                    )}
                </div>

                <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                        {t('reports.sales_rt_distribution')}
                    </h3>
                    {hasResponses ? (
                        <BarChart data={distributionData} xKey="bucket" yKeys={['count']} labels={{ count: t('reports.sales_rt_responses') }} height={300} />
                    ) : (
                        <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
                    )}
                </div>
            </div>
        </SalesReportShell>
    );
}
