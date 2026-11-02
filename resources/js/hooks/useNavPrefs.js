import { useCallback, useEffect, useState } from 'react';

/**
 * Persisted navigation preferences (Phase 2).
 *
 * - rail mode (collapsed sidebar)
 * - pinned items ("favourites")
 * - recently visited items
 *
 * Everything lives in localStorage so the shell feels stable between visits.
 * A `nav-prefs-changed` window event keeps every consumer (sidebar, command
 * palette, area sub-nav) in sync without a global store dependency.
 */

const RAIL_KEY = 'crm.nav.rail';
const PIN_KEY = 'crm.nav.pinned';
const RECENT_KEY = 'crm.nav.recent';
const RECENT_LIMIT = 5;
const CHANGE_EVENT = 'nav-prefs-changed';

function readJson(key, fallback) {
    if (typeof window === 'undefined') return fallback;
    try {
        const raw = window.localStorage.getItem(key);
        if (!raw) return fallback;
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : fallback;
    } catch {
        return fallback;
    }
}

function writeJson(key, value) {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        /* storage disabled — preferences simply do not persist */
    }
}

function notify() {
    if (typeof window === 'undefined') return;
    window.dispatchEvent(new Event(CHANGE_EVENT));
}

/** A stable identity for a nav item, used as the pin/recent key. */
export function navItemKey(item, fallback = '') {
    if (!item) return fallback;
    if (Array.isArray(item.activePattern)) return item.activePattern[0];
    return item.activePattern || item.route || item.href || item.label || fallback;
}

/** True when the current URL belongs to the given nav item. */
export function isNavItemActive(item) {
    if (!item) return false;
    if (typeof item.active === 'function') {
        try { return item.active(); } catch { return false; }
    }
    const patterns = item.activePattern
        ? (Array.isArray(item.activePattern) ? item.activePattern : [item.activePattern])
        : item.route
            ? [item.route]
            : [];
    if (patterns.length) {
        return patterns.some((pattern) => {
            try { return route().current(pattern); } catch { return false; }
        });
    }
    if (item.href && typeof window !== 'undefined') {
        return window.location.pathname === item.href;
    }
    return false;
}

export default function useNavPrefs() {
    const [collapsed, setCollapsed] = useState(false);
    const [pinned, setPinned] = useState([]);
    const [recent, setRecent] = useState([]);

    const sync = useCallback(() => {
        let rail = false;
        try {
            rail = typeof window !== 'undefined' && window.localStorage.getItem(RAIL_KEY) === '1';
        } catch {
            rail = false;
        }
        setCollapsed(rail);
        setPinned(readJson(PIN_KEY, []));
        setRecent(readJson(RECENT_KEY, []));
    }, []);

    useEffect(() => {
        sync();
        window.addEventListener(CHANGE_EVENT, sync);
        window.addEventListener('storage', sync);
        return () => {
            window.removeEventListener(CHANGE_EVENT, sync);
            window.removeEventListener('storage', sync);
        };
    }, [sync]);

    const toggleCollapsed = useCallback(() => {
        setCollapsed((prev) => {
            const next = !prev;
            try {
                window.localStorage.setItem(RAIL_KEY, next ? '1' : '0');
            } catch {
                /* ignore */
            }
            notify();
            return next;
        });
    }, []);

    const isPinned = useCallback(
        (key) => pinned.some((p) => p.key === key),
        [pinned],
    );

    const togglePin = useCallback((entry) => {
        if (!entry?.key) return;
        const current = readJson(PIN_KEY, []);
        const exists = current.some((p) => p.key === entry.key);
        const next = exists
            ? current.filter((p) => p.key !== entry.key)
            : [...current, { key: entry.key, label: entry.label, href: entry.href }];
        writeJson(PIN_KEY, next);
        notify();
    }, []);

    const recordVisit = useCallback((entry) => {
        if (!entry?.key || !entry?.href || entry.href === '#') return;
        const current = readJson(RECENT_KEY, []);
        const filtered = current.filter((p) => p.key !== entry.key);
        const next = [entry, ...filtered].slice(0, RECENT_LIMIT);
        writeJson(RECENT_KEY, next);
        notify();
    }, []);

    return { collapsed, toggleCollapsed, pinned, isPinned, togglePin, recent, recordVisit };
}
