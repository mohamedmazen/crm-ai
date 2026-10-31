import { BarChart } from '@/Components/Charts';
import { useTranslation } from 'react-i18next';
import SalesReportShell from './SalesReportShell';

function formatValue(value) {
    return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function tupleLabel(row) {
    return [row.utm_source, row.utm_medium, row.utm_campaign]
        .filter((value) => value && value !== '—')
        .join(' / ');
}

export default function Attribution({ rows, summary, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();

    const chartData = (rows ?? [])
        .filter((row) => row.won_value > 0)
        .slice(0, 10)
        .map((row) => ({ label: tupleLabel(row), won_value: row.won_value }));

    return (
        <SalesReportShell
            title={t('reports.sales_attribution_title')}
            subtitle={t('reports.sales_attribution_subtitle')}
            active="attribution"
            routeName="client.reports.sales.attribution"
            exportReport="attribution"
            filters={filters}
            pipelines={pipelines}
            agents={agents}
            sources={sources}
            summary={summary}
        >
            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_attribution_by_utm')}
                </h3>
                {chartData.length > 0 ? (
                    <BarChart
                        data={chartData}
                        xKey="label"
                        yKeys={['won_value']}
                        labels={{ won_value: t('reports.sales_won_value') }}
                        height={300}
                    />
                ) : (
                    <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
                )}
            </div>

            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_attribution_table')}
                </h3>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-neutral-200 dark:border-neutral-700">
                                <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_utm_source')}</th>
                                <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_utm_medium')}</th>
                                <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_utm_campaign')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_col_leads')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_won')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_conversion_rate')}</th>
                                <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_won_value')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                            {(rows ?? []).map((row) => (
                                <tr key={`${row.utm_source}|${row.utm_medium}|${row.utm_campaign}`}>
                                    <td className="py-2 font-medium text-neutral-800 dark:text-neutral-200">{row.utm_source}</td>
                                    <td className="py-2 text-neutral-600 dark:text-neutral-400">{row.utm_medium}</td>
                                    <td className="py-2 text-neutral-600 dark:text-neutral-400">{row.utm_campaign}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.leads)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.won)}</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{row.conversion_rate}%</td>
                                    <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.won_value)}</td>
                                </tr>
                            ))}
                            {(rows ?? []).length === 0 && (
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
