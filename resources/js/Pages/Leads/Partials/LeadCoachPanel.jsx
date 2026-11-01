import axios from 'axios';
import { Check, Copy, Lightbulb, Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/ui/Button';

/**
 * The lead coach: next step, the evidence behind it, and a reply ready to send.
 *
 * Loaded on demand rather than when the tab opens — every request is a metered
 * AI call, so the rep decides when to spend it.
 */
export default function LeadCoachPanel({ leadId }) {
    const { t } = useTranslation();
    const [advice, setAdvice] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [copied, setCopied] = useState(false);

    const load = async () => {
        setLoading(true);
        setError(null);

        try {
            const res = await axios.post(route('client.leads.ai.coach', leadId));
            setAdvice(res.data);
        } catch (err) {
            setError(err?.response?.data?.error ?? t('leads.ai_failed'));
        } finally {
            setLoading(false);
        }
    };

    const copyReply = async () => {
        try {
            await navigator.clipboard.writeText(advice.reply);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setError(t('leads.coach_copy_failed'));
        }
    };

    if (!advice) {
        return (
            <div className="rounded-xl border border-dashed border-neutral-200 p-6 text-center dark:border-neutral-800">
                <Lightbulb className="mx-auto h-6 w-6 text-amber-500" aria-hidden="true" />
                <p className="mt-2 text-sm font-medium text-neutral-800 dark:text-neutral-200">
                    {t('leads.coach_intro_title')}
                </p>
                <p className="mx-auto mt-1 max-w-md text-xs text-neutral-500 dark:text-neutral-400">
                    {t('leads.coach_intro_body')}
                </p>

                {error && <p className="mt-2 text-xs text-red-600 dark:text-red-400">{error}</p>}

                <div className="mt-4 flex justify-center">
                    <Button size="sm" onClick={load} disabled={loading}>
                        {loading ? (
                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" aria-hidden="true" />
                        ) : (
                            <Sparkles className="mr-1.5 h-4 w-4" aria-hidden="true" />
                        )}
                        {loading ? t('leads.coach_thinking') : t('leads.coach_button')}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="rounded-xl border border-brand-200 bg-brand-50/60 p-4 dark:border-brand-900 dark:bg-brand-900/20">
                <div className="flex items-start justify-between gap-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">
                        {t('leads.coach_next_step')}
                    </h3>
                    {advice.channel && (
                        <span className="shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-brand-700 shadow-sm dark:bg-neutral-900 dark:text-brand-300">
                            {t(`leads.coach_channel_${advice.channel}`)}
                        </span>
                    )}
                </div>
                <p className="mt-1.5 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    {advice.next_step}
                </p>
                {advice.why && (
                    <p className="mt-2 text-xs text-neutral-600 dark:text-neutral-300">{advice.why}</p>
                )}
                {advice.at_risk && (
                    <p className="mt-2 text-[11px] font-medium text-amber-700 dark:text-amber-400">
                        {t('leads.coach_at_risk_note')}
                    </p>
                )}
            </div>

            {advice.reply && (
                <div className="rounded-xl border border-soft p-4">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-xs font-semibold uppercase tracking-wider text-neutral-400">
                            {t('leads.coach_reply')}
                        </h3>
                        <button
                            type="button"
                            onClick={copyReply}
                            className="inline-flex items-center gap-1 text-[11px] font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                        >
                            {copied ? (
                                <Check className="h-3 w-3" aria-hidden="true" />
                            ) : (
                                <Copy className="h-3 w-3" aria-hidden="true" />
                            )}
                            {copied ? t('leads.coach_copied') : t('leads.coach_copy')}
                        </button>
                    </div>
                    <p className="mt-2 whitespace-pre-wrap text-sm text-neutral-700 dark:text-neutral-200">
                        {advice.reply}
                    </p>
                </div>
            )}

            {advice.score_reason && (
                <div>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-neutral-400">
                        {t('leads.coach_score_reason')}
                    </h3>
                    <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-300">{advice.score_reason}</p>
                </div>
            )}

            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}

            <div className="flex justify-end">
                <Button variant="secondary" size="sm" onClick={load} disabled={loading}>
                    {loading ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" aria-hidden="true" />
                    ) : (
                        <Sparkles className="mr-1.5 h-4 w-4" aria-hidden="true" />
                    )}
                    {loading ? t('leads.coach_thinking') : t('leads.coach_refresh')}
                </Button>
            </div>
        </div>
    );
}