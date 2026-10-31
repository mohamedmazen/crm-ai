import { LineChart } from '@/Components/Charts';
import { useTranslation } from 'react-i18next';
import SalesReportShell from './SalesReportShell';

export default function Volume({ rows, summary, filters, pipelines, agents, sources }) {
    const { t } = useTranslation();

    return (
        <SalesReportShell
            title={t('reports.sales_volume_title')}
            subtitle={t('reports.sales_volume_subtitle')}
            active="volume"
            routeName="client.reports.sales.volume"
            exportReport="volume"
            filters={filters}
            pipelines={pipelines}
            agents={agents}
            sources={sources}
            summary={summary}
        >
            <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                    {t('reports.sales_lead_volume')}
                </h3>
                {(summary?.leads ?? 0) > 0 ? (
                    <LineChart
                        data={rows}
                        xKey="date"
                        yKeys={['leads']}
                        labels={{ leads: t('reports.sales_col_leads') }}
                        height={320}
                    />
                ) : (
                    <p className="py-16 text-center text-sm text-neutral-400">{t('reports.no_data')}</p>
                )}
            </div>
        </SalesReportShell>
    );
}
