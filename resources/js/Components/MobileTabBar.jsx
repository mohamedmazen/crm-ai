import { Link } from '@inertiajs/react';
import { isNavItemActive } from '@/hooks/useNavPrefs';

/**
 * Mobile bottom navigation (Phase 2) — four primary destinations instead of a
 * single floating menu button. The full sidebar stays reachable from the FAB.
 */
export default function MobileTabBar({ items = [] }) {
    if (items.length === 0) return null;

    return (
        <nav
            aria-label="Primary"
            className="fixed inset-x-0 bottom-0 z-30 border-t border-neutral-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/90 dark:border-neutral-800 dark:bg-neutral-900/95 dark:supports-[backdrop-filter]:bg-neutral-900/90 lg:hidden"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
        >
            <ul className="grid grid-cols-4">
                {items.map((item, i) => {
                    const active = isNavItemActive(item);
                    return (
                        <li key={item.href ?? i}>
                            <Link
                                href={item.href}
                                aria-current={active ? 'page' : undefined}
                                className={[
                                    'flex flex-col items-center justify-center gap-0.5 py-2 text-2xs font-medium transition-colors',
                                    active
                                        ? 'text-brand-700 dark:text-brand-300'
                                        : 'text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-100',
                                ].join(' ')}
                            >
                                <span aria-hidden>{item.icon}</span>
                                <span className="truncate max-w-full px-1">{item.label}</span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
