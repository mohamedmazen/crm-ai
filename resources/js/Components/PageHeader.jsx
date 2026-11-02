import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

/**
 * Shared page header.
 *
 * One typography and spacing rule for every screen: optional icon tile,
 * breadcrumb, title, description, and the page actions on the end of the row.
 * Pass tabs/filters as `children` so they sit under the title block.
 *
 * Props:
 *   icon        — lucide component rendered in a tinted tile. Optional.
 *   title       — page heading.
 *   description — supporting line under the title. Optional.
 *   breadcrumb  — [{ label, href? }] shown above the title. Optional.
 *   actions     — buttons/links aligned to the row's end. Optional.
 *   children    — full-width row rendered under the header. Optional.
 */
export default function PageHeader({
    icon: Icon,
    title,
    description,
    breadcrumb = [],
    actions,
    children,
    className = '',
}) {
    return (
        <div className={`space-y-4 ${className}`}>
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    {breadcrumb.length > 0 && (
                        <nav aria-label="Breadcrumb" className="mb-1.5">
                            <ol className="flex flex-wrap items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {breadcrumb.map((crumb, index) => {
                                    const isLast = index === breadcrumb.length - 1;

                                    return (
                                        <li key={`${crumb.label}-${index}`} className="flex items-center gap-1">
                                            {index > 0 && (
                                                <ChevronRight
                                                    className="h-3 w-3 shrink-0 text-neutral-400 dark:text-neutral-500 rtl:rotate-180"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {crumb.href && !isLast ? (
                                                <Link
                                                    href={crumb.href}
                                                    className="rounded-sm transition-colors hover:text-neutral-700 dark:hover:text-neutral-200"
                                                >
                                                    {crumb.label}
                                                </Link>
                                            ) : (
                                                <span
                                                    className={isLast ? 'text-neutral-700 dark:text-neutral-200' : undefined}
                                                    aria-current={isLast ? 'page' : undefined}
                                                >
                                                    {crumb.label}
                                                </span>
                                            )}
                                        </li>
                                    );
                                })}
                            </ol>
                        </nav>
                    )}

                    <div className="flex items-center gap-3">
                        {Icon && (
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
                                <Icon className="h-5 w-5" aria-hidden="true" />
                            </span>
                        )}
                        <div className="min-w-0">
                            <h1 className="truncate text-xl font-semibold tracking-tight text-neutral-900 dark:text-neutral-100">
                                {title}
                            </h1>
                            {description && (
                                <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{description}</p>
                            )}
                        </div>
                    </div>
                </div>

                {actions && (
                    <div className="flex flex-wrap items-center gap-2 sm:justify-end">{actions}</div>
                )}
            </div>

            {children}
        </div>
    );
}
