import { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Search, Loader2, FileText, Users, Building2, Package, CreditCard, LayoutDashboard, User, Settings, Star, Clock } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import useNavPrefs, { navItemKey } from '@/hooks/useNavPrefs';

const ICON_MAP = {
    LayoutDashboard, Users, Building2, Package, CreditCard, FileText, User, Settings,
};

/**
 * The command palette doubles as the primary navigation surface (Phase 2).
 *
 * Pass `nav={{ home, areas }}` to make every screen jumpable from ⌘K, with
 * pinned favourites and recently visited screens shown first. Without `nav`
 * (admin) it behaves exactly as before: a server-backed entity search.
 */
export default function CommandPalette({ searchRoute, nav }) {
    const { t } = useTranslation();
    const [open, setOpen]       = useState(false);
    const [query, setQuery]     = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState(0);
    const inputRef = useRef(null);
    const abortRef = useRef(null);
    const { pinned, recent, recordVisit } = useNavPrefs();

    // Open on Cmd+K / Ctrl+K, or when the topbar trigger dispatches the event.
    useEffect(() => {
        const handler = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                setOpen((v) => !v);
            }
            if (e.key === 'Escape') setOpen(false);
        };
        const openHandler = () => setOpen(true);
        window.addEventListener('keydown', handler);
        window.addEventListener('command-palette:open', openHandler);
        return () => {
            window.removeEventListener('keydown', handler);
            window.removeEventListener('command-palette:open', openHandler);
        };
    }, []);

    useEffect(() => {
        if (open) {
            setTimeout(() => inputRef.current?.focus(), 50);
            setQuery('');
            setResults([]);
            setSelected(0);
        }
    }, [open]);

    // Debounced server search.
    useEffect(() => {
        if (!open || query.length < 2) { setResults([]); return; }

        const timer = setTimeout(() => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;
            setLoading(true);
            fetch(`${searchRoute}?q=${encodeURIComponent(query)}`, { signal: controller.signal })
                .then((r) => r.json())
                .then((data) => { setResults(data.results ?? []); setSelected(0); })
                .catch(() => {})
                .finally(() => setLoading(false));
        }, 200);

        return () => clearTimeout(timer);
    }, [query, open, searchRoute]);

    const lookup = useMemo(() => {
        const map = new Map();
        if (!nav) return map;
        if (nav.home) map.set(navItemKey(nav.home), { ...nav.home, iconElement: nav.home.icon });
        nav.areas?.forEach((area) => area.items.forEach((item) => map.set(navItemKey(item), item)));
        return map;
    }, [nav]);

    const navEntries = useMemo(() => {
        if (!nav) return [];
        const entries = [];
        if (nav.home) {
            entries.push({ key: navItemKey(nav.home), label: nav.home.label, sub: null, type: 'page', href: nav.home.href, iconElement: nav.home.icon });
        }
        nav.areas?.forEach((area) => {
            area.items.forEach((item) => {
                entries.push({ key: navItemKey(item), label: item.label, sub: area.label, type: 'page', href: item.href, iconElement: item.icon });
            });
        });
        return entries;
    }, [nav]);

    // Palette contents: pinned + recent + nav matches before server results.
    const list = useMemo(() => {
        const q = query.trim().toLowerCase();
        const resolve = (entry, type) => {
            const item = lookup.get(entry.key);
            return {
                key: entry.key,
                label: item?.label ?? entry.label,
                sub: item ? (nav?.areas?.find((a) => a.items.includes(item))?.label ?? null) : null,
                type,
                href: item?.href ?? entry.href,
                iconElement: item?.icon,
            };
        };

        if (!q) {
            if (!nav) return [];
            const entries = [
                ...pinned.map((p) => resolve(p, 'pinned')),
                ...recent.map((r) => resolve(r, 'recent')),
            ];
            const seen = new Set(entries.map((e) => e.href));
            navEntries.slice(0, 6).forEach((e) => { if (!seen.has(e.href)) entries.push(e); });
            return entries;
        }

        const local = q.length < 2
            ? []
            : navEntries.filter((e) => e.label.toLowerCase().includes(q) || e.sub?.toLowerCase().includes(q)).slice(0, 6);

        return [...local, ...(q.length >= 2 ? results : [])];
    }, [query, pinned, recent, navEntries, lookup, nav, results]);

    const navigate = useCallback((entry) => {
        if (!entry?.href) return;
        setOpen(false);
        if (entry.key) recordVisit({ key: entry.key, label: entry.label, href: entry.href });
        router.visit(entry.href);
    }, [recordVisit]);

    const handleKeyDown = (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); setSelected((s) => Math.min(s + 1, list.length - 1)); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); setSelected((s) => Math.max(s - 1, 0)); }
        if (e.key === 'Enter' && list[selected]) navigate(list[selected]);
    };

    if (!open) return null;

    const sectionIcon = (type) => {
        if (type === 'pinned') return <Star className="h-4 w-4 text-amber-500 flex-shrink-0" aria-hidden />;
        if (type === 'recent') return <Clock className="h-4 w-4 text-neutral-500 dark:text-neutral-400 flex-shrink-0" aria-hidden />;
        return null;
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center pt-[10vh] px-4">
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={() => setOpen(false)} />
            <div
                role="dialog"
                aria-modal="true"
                aria-label={t('ui.search_placeholder')}
                className="relative w-full max-w-lg overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-2xl dark:border-neutral-700 dark:bg-neutral-900"
            >
                <div className="flex items-center gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                    <Search className="h-4 w-4 flex-shrink-0 text-neutral-500 dark:text-neutral-400" />
                    <input
                        ref={inputRef}
                        type="text"
                        value={query}
                        onChange={(e) => { setQuery(e.target.value); setSelected(0); }}
                        onKeyDown={handleKeyDown}
                        placeholder={t('ui.search_placeholder')}
                        aria-label={t('ui.search_placeholder')}
                        className="flex-1 bg-transparent text-sm text-neutral-900 outline-none placeholder-neutral-400 dark:text-neutral-100"
                    />
                    {loading && <Loader2 className="h-4 w-4 animate-spin text-neutral-500 dark:text-neutral-400" />}
                    {!loading && <kbd className="hidden rounded border border-neutral-200 px-1.5 py-0.5 text-xs text-neutral-500 dark:border-neutral-700 dark:text-neutral-400 sm:inline-flex">Esc</kbd>}
                </div>

                {list.length > 0 && (
                    <ul className="max-h-80 overflow-y-auto py-2">
                        {list.map((r, i) => {
                            const Icon = ICON_MAP[r.icon] ?? FileText;
                            return (
                                <li key={`${r.type}-${r.href}-${i}`}>
                                    <button
                                        type="button"
                                        onClick={() => navigate(r)}
                                        onMouseEnter={() => setSelected(i)}
                                        className={`flex w-full items-center gap-3 px-4 py-2.5 text-left transition ${i === selected ? 'bg-brand-50 dark:bg-brand-900/20' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50'}`}
                                    >
                                        {sectionIcon(r.type) ?? (r.iconElement ?? <Icon className="h-4 w-4 flex-shrink-0 text-neutral-500 dark:text-neutral-400" />)}
                                        <div className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{r.label}</span>
                                            {r.sub && <span className="block truncate text-xs text-neutral-500 dark:text-neutral-400">{r.sub}</span>}
                                        </div>
                                        {r.type !== 'pinned' && r.type !== 'recent' && (
                                            <span className="text-xs capitalize text-neutral-500 dark:text-neutral-400">{r.type}</span>
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {query.length >= 2 && !loading && list.length === 0 && (
                    <div className="px-4 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">{t('ui.no_results_for', { query })}</div>
                )}

                {query.length < 2 && list.length === 0 && (
                    <div className="px-4 py-4 text-xs text-neutral-500 dark:text-neutral-400">{t('ui.type_to_search')}</div>
                )}

                <div className="flex items-center gap-3 border-t border-neutral-100 bg-neutral-50 px-4 py-2 dark:border-neutral-800 dark:bg-neutral-900/60">
                    <span className="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                        <kbd className="rounded border border-neutral-200 px-1 py-0.5 dark:border-neutral-700">↑↓</kbd> {t('ui.cmd_navigate')}
                    </span>
                    <span className="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                        <kbd className="rounded border border-neutral-200 px-1 py-0.5 dark:border-neutral-700">↵</kbd> {t('ui.cmd_open')}
                    </span>
                    <span className="ml-auto text-xs text-neutral-500 dark:text-neutral-400">
                        <kbd className="rounded border border-neutral-200 px-1 py-0.5 dark:border-neutral-700">⌘K</kbd> {t('ui.cmd_close')}
                    </span>
                </div>
            </div>
        </div>
    );
}
