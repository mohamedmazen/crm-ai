/**
 * Locale-aware formatting helpers (Phase 4 — Arabic typography & RTL).
 *
 * The active locale lives on <html lang>. Routing every number, currency and
 * date through these helpers keeps digits, separators and currency placement
 * correct in both LTR and RTL instead of hard-coding en-US output.
 */

export function getIntlLocale() {
    if (typeof document === 'undefined') {
        return 'en';
    }
    return document.documentElement.getAttribute('lang') || 'en';
}

function isRtlLocale(locale = getIntlLocale()) {
    return /^(ar|fa|he|ur)\b/i.test(locale);
}

export { isRtlLocale };

function toNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

export function formatNumber(value, options = {}) {
    const n = toNumber(value);
    if (n === null) {
        return '';
    }
    return new Intl.NumberFormat(getIntlLocale(), options).format(n);
}

export function formatCurrency(value, currency = 'EGP', options = {}) {
    const n = toNumber(value);
    if (n === null) {
        return '';
    }
    const code = (currency || 'EGP').toUpperCase();
    try {
        return new Intl.NumberFormat(getIntlLocale(), {
            style: 'currency',
            currency: code,
            ...options,
        }).format(n);
    } catch {
        return `${code} ${formatNumber(n, options)}`;
    }
}

export function formatPercent(value, options = {}) {
    const n = toNumber(value);
    if (n === null) {
        return '';
    }
    return new Intl.NumberFormat(getIntlLocale(), {
        style: 'percent',
        ...options,
    }).format(n);
}

function toDate(value) {
    if (value == null || value === '') {
        return null;
    }
    const d = value instanceof Date ? value : new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}

export function formatDate(value, options = {}) {
    const d = toDate(value);
    if (!d) {
        return '';
    }
    return new Intl.DateTimeFormat(getIntlLocale(), {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        ...options,
    }).format(d);
}

export function formatDateTime(value, options = {}) {
    const d = toDate(value);
    if (!d) {
        return '';
    }
    return new Intl.DateTimeFormat(getIntlLocale(), {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        ...options,
    }).format(d);
}

/**
 * "2 hours ago" / "منذ ساعتين" — Intl.RelativeTimeFormat honours the locale.
 */
export function formatRelative(value, now = Date.now()) {
    const d = toDate(value);
    if (!d) {
        return '';
    }

    const diffSeconds = Math.round((d.getTime() - now) / 1000);
    const abs = Math.abs(diffSeconds);
    const formatter = new Intl.RelativeTimeFormat(getIntlLocale(), { numeric: 'auto' });

    if (abs < 60) {
        return formatter.format(diffSeconds, 'second');
    }
    if (abs < 3600) {
        return formatter.format(Math.round(diffSeconds / 60), 'minute');
    }
    if (abs < 86400) {
        return formatter.format(Math.round(diffSeconds / 3600), 'hour');
    }
    if (abs < 2592000) {
        return formatter.format(Math.round(diffSeconds / 86400), 'day');
    }
    if (abs < 31536000) {
        return formatter.format(Math.round(diffSeconds / 2592000), 'month');
    }
    return formatter.format(Math.round(diffSeconds / 31536000), 'year');
}
