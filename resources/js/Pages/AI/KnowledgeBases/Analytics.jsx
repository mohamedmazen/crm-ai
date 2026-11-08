import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, BarChart3, FileText, Database, Bot, MessagesSquare, Coins, AlertTriangle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const STAT_CARDS = [
    { key: 'documents', icon: FileText, labelKey: 'ai.analytics_documents' },
    { key: 'chunks', icon: Database, labelKey: 'ai.analytics_chunks' },
    { key: 'chatbots', icon: Bot, labelKey: 'ai.analytics_chatbots' },
    { key: 'queries_30d', icon: MessagesSquare, labelKey: 'ai.analytics_queries' },
    { key: 'tokens_30d', icon: Coins, labelKey: 'ai.analytics_tokens' },
];

export default function AiKnowledgeBaseAnalytics({ kb, stats, daily = [], topChunks = [] }) {
    const { t, i18n } = useTranslation();
    const number = new Intl.NumberFormat(i18n.language);
    const maxQueries = Math.max(1, ...daily.map(d => d.queries));

    return (
        <ClientLayout title={t('ai.analytics_title')}>
            <Head title={`${kb.name} · ${t('ai.analytics_title')}`} />
            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Link
                        href={route('client.ai.knowledge-bases.show', kb.uuid)}
                        className="rounded-lg p-1.5 text-neutral-600 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100 truncate">{kb.name}</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{t('ai.analytics_subtitle')}</p>
                    </div>
                    <BarChart3 className="h-5 w-5 text-brand-600 dark:text-brand-400 shrink-0" />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {STAT_CARDS.map(({ key, icon: Icon, labelKey }) => (
                        <div key={key} className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center gap-2 text-neutral-500 dark:text-neutral-400">
                                <Icon className="h-4 w-4" />
                                <span className="text-xs font-medium">{t(labelKey)}</span>
                            </div>
                            <p className="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {number.format(stats[key] ?? 0)}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-2 text-neutral-500 dark:text-neutral-400">
                            <AlertTriangle className="h-4 w-4" />
                            <span className="text-xs font-medium">{t('ai.analytics_error_rate')}</span>
                        </div>
                        <p className={`mt-2 text-2xl font-semibold ${stats.error_rate_30d > 5 ? 'text-red-600 dark:text-red-400' : 'text-neutral-900 dark:text-neutral-100'}`}>
                            {number.format(stats.error_rate_30d ?? 0)}%
                        </p>
                    </div>

                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-2 text-neutral-500 dark:text-neutral-400">
                            <Database className="h-4 w-4" />
                            <span className="text-xs font-medium">{t('ai.analytics_last_indexed')}</span>
                        </div>
                        <p className="mt-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {stats.last_indexed_at ? new Date(stats.last_indexed_at).toLocaleString(i18n.language) : '—'}
                        </p>
                        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                            {kb.embedding_model} · {kb.dimensions}
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.analytics_last_14_days')}</h3>

                    {daily.every(d => d.queries === 0) ? (
                        <p className="mt-6 text-sm text-neutral-500 dark:text-neutral-400">{t('ai.analytics_no_traffic')}</p>
                    ) : (
                        <>
                            <div className="mt-6 flex h-40 items-end gap-1">
                                {daily.map((day) => (
                                    <div key={day.date} className="flex flex-1 flex-col items-center gap-1">
                                        <div
                                            className={`w-full rounded-t ${day.errors > 0 ? 'bg-red-400 dark:bg-red-500' : 'bg-brand-500 dark:bg-brand-400'}`}
                                            style={{ height: `${Math.max(day.queries > 0 ? 6 : 0, (day.queries / maxQueries) * 100)}%` }}
                                            title={`${day.date}: ${day.queries}`}
                                        />
                                    </div>
                                ))}
                            </div>
                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                                            <th className="py-2 font-medium">{t('ai.analytics_date')}</th>
                                            <th className="py-2 text-right font-medium">{t('ai.analytics_queries_short')}</th>
                                            <th className="py-2 text-right font-medium">{t('ai.analytics_tokens_short')}</th>
                                            <th className="py-2 text-right font-medium">{t('ai.analytics_errors')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                        {[...daily].reverse().filter(day => day.queries > 0).map((day) => (
                                            <tr key={day.date}>
                                                <td className="py-2 text-neutral-700 dark:text-neutral-300">{day.date}</td>
                                                <td className="py-2 text-right text-neutral-900 dark:text-neutral-100">{number.format(day.queries)}</td>
                                                <td className="py-2 text-right text-neutral-700 dark:text-neutral-300">{number.format(day.tokens)}</td>
                                                <td className={`py-2 text-right ${day.errors > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-500 dark:text-neutral-400'}`}>
                                                    {number.format(day.errors)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </div>

                <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.top_chunks_title')}</h3>

                    {topChunks.length === 0 ? (
                        <p className="mt-4 text-sm text-neutral-500 dark:text-neutral-400">{t('ai.top_chunks_empty')}</p>
                    ) : (
                        <ul className="mt-4 divide-y divide-neutral-100 dark:divide-neutral-800">
                            {topChunks.map(chunk => (
                                <li key={chunk.id} className="py-3">
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                                        {chunk.heading && <span className="rounded bg-brand-50 px-1.5 py-0.5 font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">{chunk.heading}</span>}
                                        {chunk.document && chunk.document_uuid && (
                                            <Link
                                                href={route('client.ai.knowledge-bases.documents.show', [kb.uuid, chunk.document_uuid])}
                                                className="truncate hover:text-brand-600 dark:hover:text-brand-400 transition"
                                            >
                                                {chunk.document}
                                            </Link>
                                        )}
                                        <span className="ms-auto">{t('ai.analytics_uses_30d', { uses: chunk.uses })}</span>
                                    </div>
                                    <p className="mt-1 text-sm text-neutral-800 dark:text-neutral-200">{chunk.content}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </ClientLayout>
    );
}
