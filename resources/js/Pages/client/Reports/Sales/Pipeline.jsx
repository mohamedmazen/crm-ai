import { BarChart, FunnelChart } from '@/Components/Charts';
import { useTranslation } from 'react-i18next';
import SalesReportShell from './SalesReportShell';

function formatDays(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}d`;
}

export default function Pipeline({ rows, bottlenecks, pipelineName, summary, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();

    const funnelData = (rows ?? []).map((row) => ({ name: row.stage, value: row.leads }));
    const bottleneckData = (bottlenecks ?? [])
        .filter((row) => row.avg_days !== null)
        .map((row) => ({ stage: row.stage, avg_days: row.avg_days }));
    const bottleneckByStage = Object.fromEntries((bottlenecks ?? []).map((row) => [row.stage_id, row]));

    return (
        <SalesReportShell
            title={t('reports.sales_pipeline_title')}
            subtitle={pipelineName ? t('reports.sales_pipeline_subtitle_named', { pipeline: pipelineName }) : t('reports.sales_pipeline_subtitle')}
            active="pipeline"
            routeName="client.reports.sales.pipeline"
            exportReport="pipeline"
            filters={filters}
            pipelines={pipelines}
            agents={agents}
            sources={sources}
            summary={summary}
        >
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                        {t('reports.sales_funnel')}
                    </h3>
                    {funnelData.length > 0 ? (
                        <FunnelChart data={funnelData} nameKey="name" valueKey="value" height={320} />
                    ) : (
                        <p className="py-16 text-center text-sm text-neutral-400">{t('reports.sales_no_pipeline')}</p>
                    )}
                </div>

                <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                    <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                        {t('reports.sales_bottlenecks')}
                    </h3>
                    {bottleneckData.length > 0 ? (
                        <BarChart
                            data={bottleneckData}
                            xKey="stage"
                            yKeys={['avg_days']}
                            labels={{ avg_days: t('reports.sales_avg_days') }}
                            height={320}
                        />
                    ) : (
                        <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
                    )}
                </div>
            </div>

            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_funnel_table')}
                </h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-neutral-200 dark:border-neutral-700">
                                <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_col_stage')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_col_leads')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_conversion_rate')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_step_conversion')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_avg_days')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                            {(rows ?? []).map((row) => (
                                <tr key={row.stage_id}>
                                    <td className="py-2 font-medium text-neutral-800 dark:text-neutral-200">{row.stage}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{row.leads}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{row.conversion_rate}%</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{row.step_conversion_rate}%</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">
                                        {bottleneckByStage[row.stage_id]?.is_terminal ? '—' : formatDays(bottleneckByStage[row.stage_id]?.avg_days)}
                                    </td>
                                </tr>
                            ))}
                            {(rows ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={5} className="py-6 text-center text-neutral-400">{t('reports.sales_no_pipeline')}</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </SalesReportShell>
    );
}
