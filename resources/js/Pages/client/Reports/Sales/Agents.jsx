import { BarChart } from '@/Components/Charts';
import { useTranslation } from 'react-i18next';
import SalesReportShell from './SalesReportShell';

function formatValue(value) {
    return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

export default function Agents({ rows, summary, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();

    return (
        <SalesReportShell
            title={t('reports.sales_agents_title')}
            subtitle={t('reports.sales_agents_subtitle')}
            active="agents"
            routeName="client.reports.sales.agents"
            exportReport="agents"
            filters={filters}
            pipelines={pipelines}
            agents={agents}
            sources={sources}
            summary={summary}
        >
            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_won_value_by_agent')}
                </h3>
                {rows.length > 0 ? (
                    <BarChart
                        data={rows}
                        xKey="name"
                        yKeys={['won_value', 'pipeline_value']}
                        labels={{
                            won_value: t('reports.sales_won_value'),
                            pipeline_value: t('reports.sales_pipeline_value'),
                        }}
                        height={300}
                    />
                ) : (
                    <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
                )}
            </div>

            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_agent_performance')}
                </h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-neutral-200 dark:border-neutral-700">
                                <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_col_agent')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_col_leads')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_won')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_lost')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_win_rate')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_won_value')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_pipeline_value')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                            {rows.map((row) => (
                                <tr key={row.user_id}>
                                    <td className="py-2 font-medium text-neutral-800 dark:text-neutral-200">{row.name}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.leads)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.won)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.lost)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{row.win_rate}%</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.won_value)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.pipeline_value)}</td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="py-6 text-center text-neutral-400">{t('reports.no_data')}</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </SalesReportShell>
    );
}
