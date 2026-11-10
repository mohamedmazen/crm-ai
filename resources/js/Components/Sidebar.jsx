import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, Lock, Plus, X, Search, PanelLeftClose, PanelLeftOpen, Star, Clock } from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { useBranding } from '@/hooks/useBranding';
import useNavPrefs, { navItemKey } from '@/hooks/useNavPrefs';

const MAX_VISIBLE = 4;

function safeCurrent(pattern) {
    try {
        return route().current(pattern);
    } catch {
        return false;
    }
}

function isItemActive(item) {
    if (!item) return false;
    if (typeof item.active === 'function') {
        try { return item.active(); } catch { return false; }
    }
    const patterns = item.activePattern
        ? (Array.isArray(item.activePattern) ? item.activePattern : [item.activePattern])
        : item.route
            ? [item.route]
            : [];
    if (patterns.length) return patterns.some(safeCurrent);
    if (item.href && typeof window !== 'undefined') {
        return window.location.pathname === item.href;
    }
    return false;
}

/* -------------------------------------------------------------------------- */
/* Legacy flat / grouped navigation (admin)                                    */
/* -------------------------------------------------------------------------- */

function LegacyItem({ item, onClose }) {
    const { t } = useTranslation();
    const active = isItemActive(item);
    return (
        <Link
            href={item.href ?? (item.route ? route(item.route) : '#')}
            onClick={onClose}
            className={[
                'group flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150',
                active ? 'bg-brand-600 text-white' : 'text-white/80 hover:bg-white/10 hover:text-white',
            ].join(' ')}
        >
            {item.icon && (
                <span className={active ? 'text-white' : 'text-white/65 group-hover:text-white'}>{item.icon}</span>
            )}
            <span className="truncate">{item.label}</span>
            {item.locked && <Lock className="ml-auto h-3 w-3 shrink-0 text-white/50" aria-label={t('ui.feature_locked_title')} />}
        </Link>
    );
}

function LegacyGroup({ label, items, onClose }) {
    const hasActive = items.some(isItemActive);
    const [open, setOpen] = useState(hasActive);
    const id = `nav-group-${String(label).replace(/\s+/g, '-').toLowerCase()}`;
    const { t } = useTranslation();

    useEffect(() => { if (hasActive) setOpen(true); }, [hasActive]);

    return (
        <div className="mb-0.5">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                aria-controls={id}
                className="mt-3 flex w-full items-center justify-between px-3 py-1.5 text-3xs font-bold uppercase tracking-widest text-white/70 transition-colors duration-150 hover:text-white select-none"
            >
                <span>{label}</span>
                <ChevronDown className={['h-3 w-3 transition-transform duration-200', open ? 'rotate-0' : '-rotate-90 rtl:rotate-90'].join(' ')} />
            </button>
            {open && (
                <div id={id} className="mt-0.5 space-y-0.5">
                    {items.map((item, i) => (
                        <LegacyItem key={item.key ?? item.route ?? item.href ?? i} item={item} onClose={onClose} />
                    ))}
                </div>
            )}
            {!open && null}
            {items.length === 0 && <span className="sr-only">{t('nav.group_empty', { defaultValue: 'No items' })}</span>}
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Area-based navigation (client panel)                                        */
/* -------------------------------------------------------------------------- */

function AreaLink({ item, onClose, areaKey, onVisit, collapsedIconOnly = false, isPinned, togglePin }) {
    const { t } = useTranslation();
    const active = isItemActive(item);
    const key = navItemKey(item);
    const pinned = isPinned?.(key) ?? false;

    // External entries (user manual, help site, third-party tools) must leave
    // the SPA: render a real anchor that opens in a new browser tab instead of
    // an Inertia visit, which would expect an Inertia response and fail.
    const NavLink = item.external ? 'a' : Link;
    const externalProps = item.external ? { target: '_blank', rel: 'noopener noreferrer' } : {};

    const handleClick = () => {
        onVisit?.({ key, label: item.label, href: item.href });
        onClose?.();
    };

    if (collapsedIconOnly) {
        return (
            <NavLink
                href={item.href}
                onClick={handleClick}
                title={item.label}
                aria-label={item.label}
                className={[
                    'mx-auto flex h-9 w-9 items-center justify-center rounded-lg transition-colors duration-150',
                    active ? 'bg-brand-600 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white',
                ].join(' ')}
                {...externalProps}
            >
                {item.icon}
            </NavLink>
        );
    }

    return (
        <div className="group/item relative flex items-center">
            <NavLink
                href={item.href}
                onClick={handleClick}
                className={[
                    'flex flex-1 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150',
                    active ? 'bg-brand-600 text-white shadow-sm' : 'text-white/80 hover:bg-white/10 hover:text-white',
                ].join(' ')}
                {...externalProps}
            >
                {item.icon && (
                    <span className={['shrink-0', active ? 'text-white' : 'text-white/65 group-hover/item:text-white'].join(' ')}>
                        {item.icon}
                    </span>
                )}
                <span className="truncate">{item.label}</span>
                {item.external && <span className="sr-only">(opens in a new tab)</span>}
            </NavLink>
            <button
                type="button"
                onClick={() => togglePin?.({ key, label: item.label, href: item.href })}
                aria-pressed={pinned}
                aria-label={pinned ? t('nav.unpin', { defaultValue: 'Unpin' }) : t('nav.pin', { defaultValue: 'Pin' })}
                data-area={areaKey}
                className={[
                    'absolute end-1.5 flex h-6 w-6 items-center justify-center rounded transition',
                    pinned ? 'text-amber-300' : 'text-white/0 group-hover/item:text-white/60 hover:!text-white focus-visible:text-white/80',
                ].join(' ')}
            >
                <Star className={['h-3.5 w-3.5', pinned ? 'fill-current' : ''].join(' ')} aria-hidden />
            </button>
        </div>
    );
}

function AreaSection({ area, onClose, onVisit, isPinned, togglePin }) {
    const { t } = useTranslation();
    const active = area.items.some(isItemActive);
    const [open, setOpen] = useState(active);
    const [showAll, setShowAll] = useState(false);
    const id = `nav-area-${area.key}`;

    useEffect(() => { if (active) setOpen(true); }, [active]);

    const visible = showAll ? area.items : area.items.slice(0, MAX_VISIBLE);
    const hiddenCount = area.items.length - visible.length;
    const AreaIcon = area.icon;

    if (area.items.length === 0 && area.lockedItems.length === 0) return null;

    return (
        <div className="mb-0.5">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                aria-controls={id}
                className={[
                    'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition-colors duration-150 select-none',
                    active ? 'text-white' : 'text-white/80 hover:bg-white/10 hover:text-white',
                ].join(' ')}
            >
                <span className={active ? 'text-white' : 'text-white/70'} aria-hidden>{AreaIcon}</span>
                <span className="flex-1 truncate text-start">{area.label}</span>
                <ChevronDown className={['h-3.5 w-3.5 shrink-0 transition-transform duration-200', open ? 'rotate-0' : '-rotate-90 rtl:rotate-90'].join(' ')} aria-hidden />
            </button>

            {open && (
                <div id={id} className="mt-0.5 space-y-0.5 ps-2">
                    {visible.map((item, i) => (
                        <AreaLink key={navItemKey(item, i)} item={item} areaKey={area.key} onClose={onClose} onVisit={onVisit} isPinned={isPinned} togglePin={togglePin} />
                    ))}

                    {hiddenCount > 0 && (
                        <button
                            type="button"
                            onClick={() => setShowAll(true)}
                            className="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium text-white/60 hover:bg-white/10 hover:text-white transition"
                        >
                            <Plus className="h-3 w-3" aria-hidden />
                            {t('nav.show_more', { count: hiddenCount, defaultValue: `Show ${hiddenCount} more` })}
                        </button>
                    )}
                    {showAll && area.items.length > MAX_VISIBLE && (
                        <button
                            type="button"
                            onClick={() => setShowAll(false)}
                            className="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium text-white/60 hover:bg-white/10 hover:text-white transition"
                        >
                            <ChevronDown className="h-3 w-3 rotate-180" aria-hidden />
                            {t('nav.show_less', { defaultValue: 'Show less' })}
                        </button>
                    )}

                    {area.lockedItems.length > 0 && (
                        <Link
                            href={route('client.pricing')}
                            onClick={onClose}
                            className="mt-1 flex items-center gap-2 rounded-lg border border-dashed border-white/25 px-3 py-2 text-xs font-medium text-white/75 hover:bg-white/10 hover:text-white transition"
                        >
                            <Lock className="h-3.5 w-3.5 shrink-0 text-amber-300" aria-hidden />
                            {t('nav.upgrade_features', { count: area.lockedItems.length, defaultValue: `Unlock ${area.lockedItems.length} more` })}
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}

function FavoritesSection({ title, entries, icon: SectionIcon, lookup, onClose, onVisit, isPinned, togglePin }) {
    if (!entries.length) return null;
    return (
        <div className="mb-1">
            <p className="flex items-center gap-1.5 px-3 pt-3 pb-1 text-3xs font-bold uppercase tracking-widest text-white/55">
                {SectionIcon && <SectionIcon className="h-3 w-3" aria-hidden />}
                {title}
            </p>
            <div className="space-y-0.5">
                {entries.map((entry) => {
                    const item = lookup.get(entry.key) ?? { label: entry.label, href: entry.href, icon: null };
                    return (
                        <AreaLink
                            key={entry.key}
                            item={item}
                            onClose={onClose}
                            onVisit={onVisit}
                            areaKey="favorite"
                            isPinned={isPinned}
                            togglePin={togglePin}
                        />
                    );
                })}
            </div>
        </div>
    );
}

function MenuSearch({ areas, home, onClose, onVisit, isPinned, togglePin }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');

    const results = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return [];
        const all = [];
        if (home && home.label.toLowerCase().includes(q)) all.push({ ...home, areaKey: 'home', areaLabel: home.label });
        areas.forEach((area) => {
            area.items.forEach((item) => {
                if (item.label.toLowerCase().includes(q)) {
                    all.push({ ...item, areaKey: area.key, areaLabel: area.label });
                }
            });
        });
        return all.slice(0, 12);
    }, [query, areas, home]);

    return (
        <div className="px-2 pt-3">
            <label className="relative block">
                <span className="sr-only">{t('nav.search_menu', { defaultValue: 'Search menu' })}</span>
                <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/50" aria-hidden />
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={t('nav.search_menu', { defaultValue: 'Search menu' })}
                    className="w-full rounded-lg border border-white/15 bg-white/5 py-1.5 ps-8 pe-2 text-sm text-white placeholder-white/45 outline-none focus:border-brand-400 focus:ring-1 focus:ring-brand-400"
                />
            </label>
            {query.trim() && (
                <div className="mt-1.5 space-y-0.5" role="list">
                    {results.length === 0 ? (
                        <p className="px-2 py-2 text-xs text-white/55">{t('nav.no_menu_results', { defaultValue: 'No matching screens' })}</p>
                    ) : (
                        results.map((item, i) => (
                            <AreaLink key={navItemKey(item, i)} item={item} areaKey={item.areaKey} onClose={onClose} onVisit={onVisit} isPinned={isPinned} togglePin={togglePin} />
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

function ClientNav({ home, areas, onClose, onVisit, collapsed, search }) {
    const { t } = useTranslation();
    const { pinned, recent, isPinned, togglePin } = useNavPrefs();
    const activeAreaKey = useMemo(
        () => areas.find((a) => a.items.some(isItemActive) || a.lockedItems.some(isItemActive))?.key ?? null,
        [areas],
    );
    const [openArea, setOpenArea] = useState(activeAreaKey);

    // Keep the collapsed-rail flyout in sync with the area the user is in.
    useEffect(() => {
        if (activeAreaKey) setOpenArea(activeAreaKey);
    }, [activeAreaKey]);

    const lookup = useMemo(() => {
        const map = new Map();
        if (home) map.set(navItemKey(home), { ...home, icon: home.icon });
        areas.forEach((area) => area.items.forEach((item) => map.set(navItemKey(item), item)));
        return map;
    }, [areas, home]);

    if (collapsed) {
        return (
            <nav className="flex-1 overflow-y-auto px-2 py-2 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-white/10">
                <div className="space-y-1">
                    {home && (
                        <Link
                            href={home.href}
                            title={home.label}
                            aria-label={home.label}
                            onClick={() => onVisit?.({ key: navItemKey(home), label: home.label, href: home.href })}
                            className={['mx-auto flex h-9 w-9 items-center justify-center rounded-lg transition-colors', isItemActive(home) ? 'bg-brand-600 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'].join(' ')}
                        >
                            {home.icon}
                        </Link>
                    )}
                    {areas.map((area) => (
                        <button
                            key={area.key}
                            type="button"
                            title={area.label}
                            aria-label={area.label}
                            onClick={() => setOpenArea((cur) => (cur === area.key ? null : area.key))}
                            className={['mx-auto flex h-9 w-9 items-center justify-center rounded-lg transition-colors', area.items.some(isItemActive) ? 'bg-brand-600 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'].join(' ')}
                        >
                            {area.icon}
                        </button>
                    ))}
                </div>
                {openArea && (
                    <div className="mt-2 space-y-0.5 border-t border-white/10 pt-2">
                        {(areas.find((a) => a.key === openArea)?.items ?? []).map((item, i) => (
                            <AreaLink key={navItemKey(item, i)} item={item} areaKey={openArea} onClose={onClose} onVisit={onVisit} collapsedIconOnly />
                        ))}
                    </div>
                )}
                <span className="sr-only">{t('nav.expand_hint', { defaultValue: 'Expand the sidebar to browse all screens' })}</span>
            </nav>
        );
    }

    return (
        <nav className="flex-1 overflow-y-auto px-2 pb-3 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-white/10">
            {search && <MenuSearch areas={areas} home={home} onClose={onClose} onVisit={onVisit} isPinned={isPinned} togglePin={togglePin} />}

            {pinned.length > 0 && (
                <FavoritesSection title={t('nav.pinned', { defaultValue: 'Pinned' })} entries={pinned} icon={Star} lookup={lookup} onClose={onClose} onVisit={onVisit} isPinned={isPinned} togglePin={togglePin} />
            )}
            {recent.length > 0 && (
                <FavoritesSection title={t('nav.recent', { defaultValue: 'Recently visited' })} entries={recent} icon={Clock} lookup={lookup} onClose={onClose} onVisit={onVisit} isPinned={isPinned} togglePin={togglePin} />
            )}

            <div className="mt-2 space-y-0.5">
                {home && (
                    <Link
                        href={home.href}
                        onClick={() => { onVisit?.({ key: navItemKey(home), label: home.label, href: home.href }); onClose?.(); }}
                        className={['flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition-colors', isItemActive(home) ? 'bg-brand-600 text-white' : 'text-white/80 hover:bg-white/10 hover:text-white'].join(' ')}
                    >
                        <span className={isItemActive(home) ? 'text-white' : 'text-white/70'} aria-hidden>{home.icon}</span>
                        <span className="truncate">{home.label}</span>
                    </Link>
                )}
                {areas.map((area) => (
                    <AreaSection key={area.key} area={area} onClose={onClose} onVisit={onVisit} isPinned={isPinned} togglePin={togglePin} />
                ))}
            </div>
        </nav>
    );
}

/* -------------------------------------------------------------------------- */
/* Shell                                                                       */
/* -------------------------------------------------------------------------- */

export default function Sidebar({
    // new (client)
    home,
    areas,
    // legacy
    navItems = [],
    navGroups = [],
    // shared
    open = false,
    onClose,
    footer,
    logo,
    showCreateButton = true,
    // rail (client)
    collapsed = false,
    onToggleCollapsed,
    enableCollapse = false,
    enableSearch = false,
    enableFavorites = false,
}) {
    const { t } = useTranslation();
    const { appName, logoUrl } = useBranding();
    const { recordVisit } = useNavPrefs();
    const isAreas = Array.isArray(areas) && areas.length > 0;
    const width = collapsed && enableCollapse ? 'w-64 lg:w-16' : 'w-64';

    const content = (
        <aside className={`flex h-full ${width} flex-col bg-secondary-900 dark:bg-neutral-900`}>
            <div className="flex h-14 shrink-0 items-center gap-2.5 border-b border-white/8 px-4">
                {collapsed && enableCollapse ? (
                    <span className="mx-auto flex items-center justify-center" title={appName}>
                        {logoUrl ? (
                            <span className="text-sm font-semibold text-white">
                                {(appName || 'C').charAt(0).toUpperCase()}
                            </span>
                        ) : (
                            <img src="/brand/aydra-mark-dark.png" alt={appName} className="h-7 w-7 object-contain" />
                        )}
                    </span>
                ) : logoUrl ? (
                    <img src={logoUrl} alt={appName} className="h-7 max-w-[140px] object-contain" />
                ) : logo ? (
                    logo
                ) : (
                    <ApplicationLogo tone="dark" className="h-7 max-w-[140px]" alt={appName} />
                )}
                {enableCollapse && (
                    <button
                        type="button"
                        onClick={onToggleCollapsed}
                        aria-label={collapsed ? t('nav.expand', { defaultValue: 'Expand sidebar' }) : t('nav.collapse', { defaultValue: 'Collapse sidebar' })}
                        title={collapsed ? t('nav.expand', { defaultValue: 'Expand sidebar' }) : t('nav.collapse', { defaultValue: 'Collapse sidebar' })}
                        className="ms-auto flex h-7 w-7 items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white transition"
                    >
                        {collapsed ? <PanelLeftOpen className="h-4 w-4 rtl:rotate-180" /> : <PanelLeftClose className="h-4 w-4 rtl:rotate-180" />}
                    </button>
                )}
            </div>

            {showCreateButton && (
                <div className="shrink-0 border-b border-white/8 p-3 pb-2">
                    <button
                        type="button"
                        className="flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white transition duration-150 hover:bg-brand-700"
                    >
                        <Plus className="h-4 w-4" />
                        {t('common.create')}
                    </button>
                </div>
            )}

            {isAreas ? (
                <ClientNav home={home} areas={areas} onClose={onClose} onVisit={recordVisit} collapsed={collapsed && enableCollapse} search={enableSearch} />
            ) : (
                <nav className="flex-1 overflow-y-auto px-2 py-2 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-white/10">
                    {navGroups.length > 0 &&
                        navGroups.map((group, gi) => (
                            <LegacyGroup
                                key={`${gi}-${group.key ?? group.label ?? ''}`}
                                label={group.label}
                                items={group.items ?? []}
                                onClose={onClose}
                            />
                        ))}
                    {navGroups.length === 0 &&
                        navItems.map((item, i) => {
                            if (item.type === 'divider') {
                                return <hr key={`div-${i}`} className="my-2 border-white/10" />;
                            }
                            return <LegacyItem key={item.key ?? item.route ?? item.href ?? i} item={item} onClose={onClose} />;
                        })}
                </nav>
            )}

            {footer && (
                <div className="shrink-0 border-t border-white/8 p-3">
                    <div className="text-white/55">{footer}</div>
                </div>
            )}
        </aside>
    );

    return (
        <>
            {/* Desktop: always visible */}
            <div className={[
                'hidden lg:fixed lg:inset-y-0 lg:z-20 lg:flex lg:flex-col lg:start-0',
                collapsed && enableCollapse ? 'lg:w-16' : 'lg:w-64',
            ].join(' ')}>
                {content}
            </div>

            {/* Mobile: overlay + drawer */}
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
                    <div className="fixed inset-y-0 start-0 w-64 shadow-2xl">
                        <button
                            type="button"
                            onClick={onClose}
                            className="absolute end-3 top-3 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-white/10 text-white/70 transition hover:bg-white/20"
                            aria-label={t('ui.close_menu')}
                        >
                            <X className="h-4 w-4" />
                        </button>
                        {content}
                    </div>
                </div>
            )}
        </>
    );
}
