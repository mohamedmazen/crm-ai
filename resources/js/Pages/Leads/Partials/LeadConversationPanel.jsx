import SlaBadge from '@/Components/SlaBadge';
import { CHANNEL_LABELS } from '@/Components/BrandIcons';
import { Link } from '@inertiajs/react';
import {
    CheckSquare, Clock, ExternalLink, Megaphone, MessageCircle,
    Send, ShoppingBag,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * The lead's conversation-intelligence tab: the linked inbox thread plus a
 * merged feed of messages, campaigns, commerce orders and follow-up tasks.
 *
 * Everything is already shaped by UnifiedTimelineService, so this component
 * only picks an icon and a headline per entry kind.
 */
const ITEM_ICONS = {
    campaign: Megaphone,
    order: ShoppingBag,
    task: CheckSquare,
};

function useRelativeTime() {
    const { i18n, t } = useTranslation();

    return (iso) => {
        if (!iso) {
            return '';
        }

        const seconds = Math.round((new Date(iso) - new Date()) / 1000);
        const units = [
            ['year', 31536000], ['month', 2592000], ['week', 604800],
            ['day', 86400], ['hour', 3600], ['minute', 60],
        ];

        for (const [unit, secs] of units) {
            if (Math.abs(seconds) >= secs) {
                return new Intl.RelativeTimeFormat(i18n.language, { numeric: 'auto' })
                    .format(Math.round(seconds / secs), unit);
            }
        }

        return t('leads.just_now');
    };
}

function formatOrderTotal(meta) {
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: meta.currency || 'USD',
        }).format(Number(meta.total ?? 0));
    } catch {
        return String(meta.total ?? '');
    }
}

export default function LeadConversationPanel({ conversation, timeline = [] }) {
    const { t } = useTranslation();
    const relative = useRelativeTime();

    return (
        <div className="space-y-5">
            {conversation ? (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-neutral-100 bg-neutral-50/60 p-3 dark:border-neutral-800 dark:bg-neutral-800/20">
                    <div className="flex items-center gap-2">
                        <MessageCircle className="h-4 w-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                        <span className="text-sm font-medium text-neutral-800 dark:text-neutral-200">
                            {t('leads.conversation_channel', { channel: CHANNEL_LABELS[conversation.channel] ?? conversation.channel ?? 'chat' })}
                        </span>
                        {conversation.unread_count > 0 && (
                            <span className="rounded-full bg-brand-600 px-1.5 py-px text-[10px] font-bold text-white">
                                {t('leads.conversation_unread', { count: conversation.unread_count })}
                            </span>
                        )}
                        <SlaBadge awaitingSince={conversation.awaiting_since} />
                    </div>

                    <Link
                        href={route('client.inbox.show', conversation.uuid)}
                        className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                    >
                        {t('leads.conversation_open_inbox')}
                        <ExternalLink className="h-3 w-3" aria-hidden="true" />
                    </Link>
                </div>
            ) : (
                <p className="rounded-xl border border-dashed border-neutral-200 p-3 text-center text-xs text-neutral-400 dark:border-neutral-700">
                    {t('leads.conversation_none')}
                </p>
            )}

            {timeline.length === 0 ? (
                <p className="py-6 text-center text-sm text-neutral-400 dark:text-neutral-600">
                    {t('leads.conversation_empty')}
                </p>
            ) : (
                <ol className="relative space-y-4 pl-6">
                    <span className="absolute bottom-2 left-[11px] top-2 w-px bg-neutral-200 dark:bg-neutral-800" aria-hidden="true" />

                    {timeline.map((item) => {
                        const inbound = item.type === 'message' && item.direction === 'in';
                        const Icon = item.type === 'message'
                            ? (inbound ? MessageCircle : Send)
                            : (ITEM_ICONS[item.type] ?? MessageCircle);
                        const tone = inbound
                            ? 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300'
                            : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400';

                        return (
                            <li key={item.id} className="relative">
                                <span className={`absolute -left-6 flex h-[22px] w-[22px] items-center justify-center rounded-full ring-4 ring-white dark:ring-neutral-900 ${tone}`}>
                                    <Icon className="h-3 w-3" aria-hidden="true" />
                                </span>

                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200">
                                        {headline(item, t)}
                                    </p>
                                    {metaBadge(item, t)}
                                </div>

                                {item.body && (
                                    <p className="mt-0.5 whitespace-pre-wrap text-sm text-neutral-600 dark:text-neutral-400">{item.body}</p>
                                )}

                                <p className="mt-0.5 flex items-center gap-1 text-xs text-neutral-400 dark:text-neutral-500">
                                    <Clock className="h-3 w-3" aria-hidden="true" />
                                    <time dateTime={item.occurred_at} title={item.occurred_at ? new Date(item.occurred_at).toLocaleString() : ''}>
                                        {relative(item.occurred_at)}
                                    </time>
                                </p>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}

function headline(item, t) {
    switch (item.type) {
        case 'message':
            return item.direction === 'in'
                ? t('leads.timeline_inbound')
                : t('leads.timeline_outbound');
        case 'campaign':
            return `${t('leads.timeline_campaign')}: ${item.title ?? ''}`.trim();
        case 'order':
            return `${t('leads.timeline_order')}: ${item.title ?? ''}`.trim();
        case 'task':
            return item.title ?? t('leads.timeline_task');
        default:
            return item.title ?? '';
    }
}

function metaBadge(item, t) {
    if (item.type === 'order') {
        return (
            <span className="rounded-soft bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                {formatOrderTotal(item.meta ?? {})}
            </span>
        );
    }

    if (item.type === 'task') {
        if (item.meta?.is_overdue) {
            return (
                <span className="rounded-soft bg-coral-100 px-1.5 py-0.5 text-xs text-coral-700 dark:bg-coral-950/50 dark:text-coral-300">
                    {t('leads.timeline_task_overdue')}
                </span>
            );
        }

        if (item.meta?.is_completed) {
            return (
                <span className="rounded-soft bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                    {t('leads.timeline_task_completed')}
                </span>
            );
        }
    }

    return null;
}
