import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, ChevronLeft, ChevronRight, Database, FileText, Hash, Loader2, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const STATUS_COLORS = {
    indexed: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    indexing: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    pending: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    error: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

function Pagination({ chunks }) {
    const { t } = useTranslation();

    if (!chunks || chunks.last_page <= 1) return null;

    const prev = chunks.links?.[0]?.url;
    const next = chunks.links?.[chunks.links.length - 1]?.url;

    return (
        <div className="flex items-center justify-between gap-3">
            {prev ? (
                <Link href={prev} preserveScroll className="flex items-center gap-1 rounded-lg border border-neutral-200 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800 transition">
                    <ChevronLeft className="h-3.5 w-3.5" /> {t('common.previous')}
                </Link>
            ) : <span />}

            <span className="text-xs text-neutral-500 dark:text-neutral-400">
                {t('ai.page_of', { current: chunks.current_page, total: chunks.last_page })}
            </span>

            {next ? (
                <Link href={next} preserveScroll className="flex items-center gap-1 rounded-lg border border-neutral-200 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800 transition">
                    {t('common.next')} <ChevronRight className="h-3.5 w-3.5" />
                </Link>
            ) : <span />}
        </div>
    );
}

export default function AiKnowledgeBaseDocument({ kb, document, chunks }) {
    const { t, i18n } = useTranslation();
    const number = new Intl.NumberFormat(i18n.language);

    return (
        <ClientLayout title={t('ai.preview_title')}>
            <Head title={`${document.title || kb.name} · ${t('ai.preview_title')}`} />
            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Link
                        href={route('client.ai.knowledge-bases.show', kb.uuid)}
                        className="rounded-lg p-1.5 text-neutral-600 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100 truncate">
                            {document.title || document.source_ref || kb.name}
                        </h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{t('ai.preview_subtitle')}</p>
                    </div>
                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_COLORS[document.status] ?? STATUS_COLORS.pending}`}>
                        {t(`ai.doc_status_${document.status}`)}
                    </span>
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-2 text-neutral-500 dark:text-neutral-400">
                            <FileText className="h-4 w-4" />
                            <span className="text-xs font-medium">{t('ai.source_type')}</span>
                        </div>
                        <p className="mt-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{t(`ai.source_${document.source_type}`)}</p>
                    </div>
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-2 text-neutral-500 dark:text-neutral-400">
                            <Hash className="h-4 w-4" />
                            <span className="text-xs font-medium">{t('ai.analytics_chunks')}</span>
                        </div>
                        <p className="mt-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{number.format(document.chunk_count ?? 0)}</p>
                    </div>
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-2 text-neutral-500 dark:text-neutral-400">
                            <Database className="h-4 w-4" />
                            <span className="text-xs font-medium">{t('ai.tokens')}</span>
                        </div>
                        <p className="mt-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{number.format(document.tokens ?? 0)}</p>
                    </div>
                    <div className="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-2 text-neutral-500 dark:text-neutral-400">
                            <Sparkles className="h-4 w-4" />
                            <span className="text-xs font-medium">{t('ai.analytics_last_indexed')}</span>
                        </div>
                        <p className="mt-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {document.last_indexed_at ? new Date(document.last_indexed_at).toLocaleString(i18n.language) : '—'}
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                        <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.preview_chunks_title')}</h3>
                        <span className="text-xs text-neutral-500 dark:text-neutral-400">{kb.embedding_model} · {kb.dimensions}</span>
                    </div>

                    {chunks.data.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">{t('ai.preview_no_chunks')}</p>
                    ) : (
                        <ul className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {chunks.data.map(chunk => (
                                <li key={chunk.id} className="px-4 py-4">
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                                        <span className="rounded bg-neutral-100 px-1.5 py-0.5 font-medium dark:bg-neutral-800">#{chunk.ord + 1}</span>
                                        {chunk.heading && <span className="rounded bg-brand-50 px-1.5 py-0.5 font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">{chunk.heading}</span>}
                                        <span>{t('ai.preview_chunk_tokens', { tokens: chunk.tokens ?? 0 })}</span>
                                        <span className="flex items-center gap-1">
                                            <Sparkles className="h-3 w-3" /> {t('ai.preview_chunk_uses', { uses: chunk.used_30d ?? 0 })}
                                        </span>
                                        {chunk.embedded ? (
                                            <span className="text-green-600 dark:text-green-400">{t('ai.preview_embedded')}</span>
                                        ) : (
                                            <span className="flex items-center gap-1 text-yellow-600 dark:text-yellow-400">
                                                <Loader2 className="h-3 w-3" /> {t('ai.preview_not_embedded')}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed text-neutral-800 dark:text-neutral-200">
                                        {chunk.content}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">
                        <Pagination chunks={chunks} />
                    </div>
                </div>
            </div>
        </ClientLayout>
    );
}