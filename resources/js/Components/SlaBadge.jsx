import { Clock } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Flags a conversation whose contact has been waiting on a reply for longer
 * than the SLA allows. Below the first threshold it renders nothing, so it can
 * be dropped into a list row without any conditional wrapper.
 *
 * `awaitingSince` is computed server-side from the last message direction and
 * is only sent for plans that include conversation intelligence.
 */
const WARN_MINUTES = 60;
const DANGER_MINUTES = 240;

function humanize(minutes) {
    if (minutes < 60) {
        return `${minutes}m`;
    }

    if (minutes < 1440) {
        return `${Math.floor(minutes / 60)}h`;
    }

    return `${Math.floor(minutes / 1440)}d`;
}

export default function SlaBadge({ awaitingSince, className = '' }) {
    const { t } = useTranslation();

    if (!awaitingSince) {
        return null;
    }

    const minutes = Math.floor((new Date().getTime() - new Date(awaitingSince).getTime()) / 60000);

    if (!Number.isFinite(minutes) || minutes < WARN_MINUTES) {
        return null;
    }

    const breached = minutes >= DANGER_MINUTES;
    const tone = breached
        ? 'bg-coral-100 text-coral-700 dark:bg-coral-950/50 dark:text-coral-300'
        : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-px text-[10px] font-semibold ${tone} ${className}`}
            title={new Date(awaitingSince).toLocaleString()}
        >
            <Clock className="h-2.5 w-2.5" aria-hidden="true" />
            {t('inbox.sla_waiting', { duration: humanize(minutes) })}
        </span>
    );
}
