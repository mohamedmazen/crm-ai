import axios from 'axios';
import { ClipboardList, FileText, Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/ui/Button';

const sentimentStyles = {
    positive: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    neutral: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
    negative: 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300',
};

/**
 * The summary of one call, with the transcript the summary was made from.
 *
 * Shared by the call log table and the lead detail modal. Both read the same
 * columns, so a summary generated in one place is visible in the other — the
 * transcript itself stays out of list payloads and is only sent on demand.
 */
export default function CallSummaryPanel({ call, aiEnabled = false, compact = false, onSaved }) {
    const { t } = useTranslation();
    const [summary, setSummary] = useState(call.summary || null);
    const [data, setData] = useState(call.summary_data || null);
    const [generatedAt, setGeneratedAt] = useState(call.summary_generated_at || null);
    const [hasTranscript, setHasTranscript] = useState(Boolean(call.has_transcript) || Boolean(call.transcript));
    const [transcript, setTranscript] = useState(call.transcript || '');
    const [editing, setEditing] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    if (!aiEnabled && !summary) {
        return null;
    }

    const generate = async () => {
        setLoading(true);
        setError(null);

        try {
            // An empty box means "use the transcript already stored on the call".
            const payload = transcript.trim() === '' ? {} : { transcript };
            const res = await axios.post(route('client.sales.calls.summary', call.id), payload);

            setSummary(res.data.summary);
            setData(res.data.summary_data || null);
            setGeneratedAt(res.data.generated_at || null);
            setHasTranscript(true);
            setEditing(false);
            onSaved?.(res.data);
        } catch (err) {
            setError(err?.response?.data?.error ?? t('calls.summary_failed'));
        } finally {
            setLoading(false);
        }
    };

    const form = (
        <div className="space-y-2">
            <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                {t('calls.transcript')}
            </label>
            <textarea
                rows={compact ? 4 : 6}
                value={transcript}
                onChange={(event) => setTranscript(event.target.value)}
                placeholder={hasTranscript ? t('calls.transcript_stored') : t('calls.transcript_hint')}
                className="w-full rounded-lg border-soft bg-white p-2.5 text-xs text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100"
            />

            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}

            <div className="flex items-center justify-end gap-2">
                {summary && (
                    <Button size="sm" variant="secondary" onClick={() => setEditing(false)}>
                        {t('common.cancel')}
                    </Button>
                )}
                <Button
                    size="sm"
                    onClick={generate}
                    disabled={loading || (!hasTranscript && transcript.trim() === '')}
                >
                    {loading
                        ? <Loader2 className="me-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                        : <Sparkles className="me-1.5 h-3.5 w-3.5" aria-hidden="true" />}
                    {loading
                        ? t('calls.summary_thinking')
                        : summary ? t('calls.summary_regenerate') : t('calls.summary_generate')}
                </Button>
            </div>
        </div>
    );

    // In a dense list the panel collapses to one button until it has something to show.
    if (compact && !summary && !editing) {
        return (
            <button
                type="button"
                onClick={() => setEditing(true)}
                className="inline-flex items-center gap-1 text-[11px] font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
            >
                <Sparkles className="h-3.5 w-3.5" aria-hidden="true" />
                {t('calls.summary_open')}
            </button>
        );
    }

    return (
        <div className={compact ? 'space-y-2' : 'space-y-3'}>
            {summary && (
                <div className="rounded-lg border border-brand-200/60 bg-brand-50/50 p-3 dark:border-brand-900 dark:bg-brand-900/20">
                    <div className="flex items-start justify-between gap-2">
                        <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">
                            <Sparkles className="h-3.5 w-3.5" aria-hidden="true" />
                            {t('calls.summary_title')}
                        </span>
                        <div className="flex shrink-0 items-center gap-2">
                            {data?.sentiment && (
                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${sentimentStyles[data.sentiment] || sentimentStyles.neutral}`}>
                                    {t(`calls.summary_sentiment_${data.sentiment}`)}
                                </span>
                            )}
                            {aiEnabled && (
                                <button
                                    type="button"
                                    onClick={() => setEditing((value) => !value)}
                                    className="text-neutral-400 hover:text-brand-600 dark:hover:text-brand-400"
                                    title={t('calls.transcript_edit')}
                                    aria-label={t('calls.transcript_edit')}
                                >
                                    <FileText className="h-3.5 w-3.5" aria-hidden="true" />
                                </button>
                            )}
                        </div>
                    </div>

                    <p className="mt-1.5 text-xs text-neutral-700 dark:text-neutral-200">{summary}</p>

                    {data?.action_items?.length > 0 && (
                        <div className="mt-2">
                            <p className="inline-flex items-center gap-1 text-[11px] font-semibold text-neutral-500 dark:text-neutral-400">
                                <ClipboardList className="h-3 w-3" aria-hidden="true" />
                                {t('calls.summary_action_items')}
                            </p>
                            <ul className="mt-1 list-disc space-y-0.5 ps-4 text-xs text-neutral-600 dark:text-neutral-300">
                                {data.action_items.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {generatedAt && (
                        <p className="mt-1.5 text-[10px] text-neutral-400">
                            {t('calls.summary_generated_at', { date: new Date(generatedAt).toLocaleString() })}
                        </p>
                    )}
                </div>
            )}

            {aiEnabled && (editing || !summary) && form}
        </div>
    );
}