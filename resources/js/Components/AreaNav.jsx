import { Link } from '@inertiajs/react';
import { isNavItemActive } from '@/hooks/useNavPrefs';

/**
 * Horizontal sub-navigation for the current area (Phase 2).
 *
 * Rendered under the topbar so every screen inside an area is one click away,
 * without repeating the whole sidebar. Hidden when the area has fewer than two
 * screens, or when the route does not belong to a known area.
 */
export default function AreaNav({ areas = [] }) {
    const area = areas.find((a) => a.items.some(isNavItemActive) || a.lockedItems.some(isNavItemActive));

    if (!area || area.items.length < 2) return null;

    return (
        <div className="sticky top-14 z-20 border-b border-neutral-200 bg-white/90 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:border-neutral-800 dark:bg-neutral-900/90 dark:supports-[backdrop-filter]:bg-neutral-900/80">
            <nav
                aria-label={area.label}
                className="flex items-center gap-1 overflow-x-auto px-2 sm:px-4 lg:px-8 scrollbar-thin scrollbar-track-transparent"
            >
                {area.items.map((item, i) => {
                    const active = isNavItemActive(item);
                    // External entries (user manual, help site) are plain anchors
                    // that open in a new tab; an Inertia visit would fail because
                    // static docs do not return an Inertia response.
                    const NavLink = item.external ? 'a' : Link;
                    const externalProps = item.external ? { target: '_blank', rel: 'noopener noreferrer' } : {};
                    return (
                        <NavLink
                            key={item.activePattern ?? item.href ?? i}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            className={[
                                'relative whitespace-nowrap px-3 py-2.5 text-sm font-medium transition-colors duration-150',
                                active
                                    ? 'text-brand-700 dark:text-brand-300'
                                    : 'text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100',
                            ].join(' ')}
                            {...externalProps}
                        >
                            {item.label}
                            {item.external && <span className="sr-only">(opens in a new tab)</span>}
                            {active && (
                                <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-600 dark:bg-brand-400" aria-hidden />
                            )}
                        </NavLink>
                    );
                })}
            </nav>
        </div>
    );
}
