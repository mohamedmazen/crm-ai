import { DonutChart } from '@/Components/Charts';
import { useTranslation } from 'react-i18next';
import SalesReportShell from './SalesReportShell';

function formatValue(value) {
    return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function ReasonCard({ title, rows }) {
    const { t } = useTranslation();
    const data = rows.map((row) => ({ name: row.reason, value: row.count }));

    return (
        <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{title}</h3>
            {data.length > 0 ? (
                <DonutChart data={data} nameKey="name" valueKey="value" height={280} />
            ) : (
                <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
            )}

            {rows.length > 0 && (
                <table className="mt-4 w-full text-sm">
                    <thead>
                        <tr className="border-b border-neutral-200 dark:border-neutral-700">
                            <th className="pb-2 text-left text-xs text-neutral-500">{t('reports.sales_col_reason')}</th>
                            <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_col_count')}</th>
                            <th className="pb-2 text-right text-xs text-neutral-500">{t('reports.sales_col_value')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                        {rows.map((row) => (
                            <tr key={`${row.type}-${row.reason}`}>
                                <td className="py-2 text-neutral-800 dark:text-neutral-200">{row.reason}</td>
                                <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.count)}</td>
                                <td className="py-2 text-right text-neutral-600 dark:text-neutral-400">{formatValue(row.value)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

export default function WinLoss({ rows, summary, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();
    const wonRows = rows.filter((row) => row.type === 'won');
    const lostRows = rows.filter((row) => row.type === 'lost');

    return (
        <SalesReportShell
            title={t('reports.sales_win_loss_title')}
            subtitle={t('reports.sales_win_loss_subtitle')}
            active="win-loss"
            routeName="client.reports.sales.win-loss"
            exportReport="win-loss"
            filters={filters}
            pipelines={pipelines}
            agents={agents}
            sources={sources}
            summary={summary}
        >
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <ReasonCard title={t('reports.sales_won_reasons')} rows={wonRows} />
                <ReasonCard title={t('reports.sales_lost_reasons')} rows={lostRows} />
            </div>
        </SalesReportShell>
    );
}
