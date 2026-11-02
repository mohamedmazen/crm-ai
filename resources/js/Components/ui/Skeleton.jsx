/**
 * Skeleton loading placeholders (Phase 4 — polish).
 *
 * Usage:
 *   <Skeleton className="h-4 w-48" />
 *   <Skeleton variant="circle" className="h-10 w-10" />
 *   <Skeleton variant="text" lines={3} />
 *   <TableSkeleton rows={8} columns={5} />
 *   <ListSkeleton rows={6} />
 */
export default function Skeleton({ className = '', variant = 'rect', lines = 1, ...props }) {
    const base = 'animate-pulse bg-neutral-200 dark:bg-neutral-700 rounded';

    if (variant === 'circle') {
        return <div className={`${base} rounded-full ${className}`} {...props} />;
    }

    if (variant === 'text') {
        return (
            <div className={`space-y-2 ${className}`} {...props}>
                {Array.from({ length: lines }).map((_, i) => (
                    <div
                        key={i}
                        className={`${base} h-4 ${i === lines - 1 && lines > 1 ? 'w-3/4' : 'w-full'}`}
                    />
                ))}
            </div>
        );
    }

    return <div className={`${base} ${className}`} {...props} />;
}

/**
 * Placeholder for the shared DataTable while a list request is in flight.
 * Mirrors the real table geometry so the page does not jump when data lands.
 */
export function TableSkeleton({ rows = 6, columns = 5, className = '' }) {
    return (
        <div className={`overflow-hidden ${className}`} aria-hidden="true" data-testid="table-skeleton">
            <div className="flex items-center gap-4 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                {Array.from({ length: columns }).map((_, i) => (
                    <div key={i} className="h-3 flex-1 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700" />
                ))}
            </div>
            {Array.from({ length: rows }).map((_, r) => (
                <div key={r} className="flex items-center gap-4 border-b border-neutral-50 px-4 py-3.5 last:border-0 dark:border-neutral-800/60">
                    {Array.from({ length: columns }).map((_, c) => (
                        <div
                            key={c}
                            className="h-4 flex-1 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"
                            style={{ opacity: 1 - r * 0.08 }}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

/**
 * Placeholder for a vertical list (Inbox conversations, notes, activity feed).
 */
export function ListSkeleton({ rows = 6, className = '' }) {
    return (
        <div className={`divide-y divide-neutral-100 dark:divide-neutral-800 ${className}`} aria-hidden="true" data-testid="list-skeleton">
            {Array.from({ length: rows }).map((_, i) => (
                <div key={i} className="flex items-start gap-2.5 px-3 py-3">
                    <div className="h-9 w-9 shrink-0 animate-pulse rounded-full bg-neutral-200 dark:bg-neutral-700" />
                    <div className="min-w-0 flex-1 space-y-2">
                        <div className="h-3.5 w-2/5 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700" />
                        <div className="h-3 w-3/4 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700" />
                    </div>
                </div>
            ))}
        </div>
    );
}

/**
 * Placeholder for a KPI / summary card.
 */
export function CardSkeleton({ className = '' }) {
    return (
        <div className={`rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900 ${className}`} aria-hidden="true">
            <div className="h-3 w-1/3 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700" />
            <div className="mt-3 h-7 w-1/2 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700" />
        </div>
    );
}
