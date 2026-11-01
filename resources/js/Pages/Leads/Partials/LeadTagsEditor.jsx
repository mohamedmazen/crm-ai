import axios from 'axios';
import { Loader2, Plus, Sparkles, Tag, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * The lead's tags plus, on plans with AI sales assist, a way to have the model
 * propose more.
 *
 * Suggestions are never applied automatically: tags drive filters and segments,
 * so the user taps the ones worth keeping. Each tap is one attach request, and
 * every suggestion request is one metered AI run.
 */
export default function LeadTagsEditor({ leadId, initialTags = [], aiEnabled = false }) {
    const { t } = useTranslation();
    const [tags, setTags] = useState(initialTags);
    const [suggestions, setSuggestions] = useState([]);
    const [suggesting, setSuggesting] = useState(false);
    const [applying, setApplying] = useState(false);
    const [error, setError] = useState(null);

    if (tags.length === 0 && !aiEnabled) {
        return null;
    }

    const suggest = async () => {
        setSuggesting(true);
        setError(null);

        try {
            const res = await axios.post(route('client.leads.ai.tags.suggest', leadId));
            setSuggestions(res.data.tags ?? []);
        } catch (err) {
            setError(err?.response?.data?.error ?? t('leads.ai_failed'));
        } finally {
            setSuggesting(false);
        }
    };

    const apply = async (names) => {
        if (names.length === 0) return;

        setApplying(true);
        setError(null);

        try {
            const res = await axios.post(route('client.leads.ai.tags.apply', leadId), { tag_names: names });
            setTags(res.data.tags ?? []);
            setSuggestions((current) =>
                current.filter((name) => !names.some((added) => added.toLowerCase() === name.toLowerCase()))
            );
        } catch (err) {
            setError(err?.response?.data?.error ?? t('leads.ai_failed'));
        } finally {
            setApplying(false);
        }
    };

    return (
        <div className="mt-2.5 space-y-1.5">
            <div className="flex flex-wrap items-center gap-1.5">
                <Tag className="h-3.5 w-3.5 text-neutral-400" aria-hidden="true" />
                {tags.map((tag) => (
                    <span
                        key={tag.id}
                        className="inline-flex items-center rounded-full border border-brand-200/50 bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-300"
                    >
                        {tag.name}
                    </span>
                ))}
                {aiEnabled && (
                    <button
                        type="button"
                        onClick={suggest}
                        disabled={suggesting}
                        className="inline-flex items-center gap-1 rounded-full border border-dashed border-brand-300 px-2 py-0.5 text-[11px] font-medium text-brand-600 transition-colors hover:bg-brand-50 disabled:opacity-60 dark:border-brand-800 dark:text-brand-400 dark:hover:bg-brand-900/20"
                    >
                        {suggesting ? (
                            <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" />
                        ) : (
                            <Sparkles className="h-3 w-3" aria-hidden="true" />
                        )}
                        {suggesting ? t('leads.ai_suggesting') : t('leads.ai_suggest_tags')}
                    </button>
                )}
            </div>

            {suggestions.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-[11px] text-neutral-400">{t('leads.ai_tag_suggestions')}</span>
                    {suggestions.map((name) => (
                        <button
                            key={name}
                            type="button"
                            onClick={() => apply([name])}
                            disabled={applying}
                            className="inline-flex items-center gap-0.5 rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-700 transition-colors hover:bg-brand-100 hover:text-brand-700 disabled:opacity-60 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-brand-900/40 dark:hover:text-brand-300"
                        >
                            <Plus className="h-3 w-3" aria-hidden="true" />
                            {name}
                        </button>
                    ))}
                    {suggestions.length > 1 && (
                        <button
                            type="button"
                            onClick={() => apply(suggestions)}
                            disabled={applying}
                            className="text-[11px] font-medium text-brand-600 underline hover:text-brand-700 disabled:opacity-60 dark:text-brand-400"
                        >
                            {t('leads.ai_add_all')}
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={() => setSuggestions([])}
                        className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200"
                        title={t('common.close')}
                        aria-label={t('common.close')}
                    >
                        <X className="h-3 w-3" aria-hidden="true" />
                    </button>
                </div>
            )}

            {error && <p className="text-[11px] text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}