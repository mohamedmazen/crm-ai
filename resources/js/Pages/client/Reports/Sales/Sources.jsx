import { BarChart } from '@/Components/Charts';
import { useTranslation } from 'react-i18next';
import SalesReportShell from './SalesReportShell';

function formatValue(value) {
    return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

export default function Sources({ rows, summary, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();

    return (
        <SalesReportShell
            title={t('reports.sales_sources_title')}
            subtitle={t('reports.sales_sources_subtitle')}
            active="sources"
            routeName="client.reports.sales.sources"
            exportReport="sources"
            filters={filters}
            pipelines={pipelines}
            agents={agents}
            sources={sources}
            summary={summary}
        >
            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_leads_by_source')}
                </h3>
                {rows.length > 0 ? (
                    <BarChart
                        data={rows}
                        xKey="source"
                        yKeys={['leads', 'won', 'lost']}
                        labels={{
                            leads: t('reports.sales_col_leads'),
                            won: t('reports.sales_won'),
                            lost: t('reports.sales_lost'),
                        }}
                        height={300}
                    />
                ) : (
                    <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
                )}
            </div>

            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_source_performance')}
                </h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-neutral-200 dark:border-neutral-700">
                                <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_col_source')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_col_leads')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_won')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_lost')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_conversion_rate')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_won_value')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                            {rows.map((row) => (
                                <tr key={row.source}>
                                    <td className="py-2 font-medium text-neutral-800 dark:text-neutral-200">{row.source}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.leads)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.won)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.lost)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{row.conversion_rate}%</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.won_value)}</td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="py-6 text-center text-neutral-400">{t('reports.no_data')}</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </SalesReportShell>
    );
}
