import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
    getIntlLocale,
    isRtlLocale,
    formatNumber,
    formatCurrency,
    formatPercent,
    formatDate,
    formatDateTime,
    formatRelative,
} from '@/Utils/format';

describe('format helpers', () => {
    const originalLang = document.documentElement.getAttribute('lang');

    beforeEach(() => {
        document.documentElement.setAttribute('lang', 'en');
    });

    afterEach(() => {
        if (originalLang === null) {
            document.documentElement.removeAttribute('lang');
        } else {
            document.documentElement.setAttribute('lang', originalLang);
        }
    });

    it('reads the active locale from <html lang>', () => {
        expect(getIntlLocale()).toBe('en');
        document.documentElement.setAttribute('lang', 'ar');
        expect(getIntlLocale()).toBe('ar');
    });

    it('detects RTL locales', () => {
        expect(isRtlLocale('ar')).toBe(true);
        expect(isRtlLocale('ar-SA')).toBe(true);
        expect(isRtlLocale('he')).toBe(true);
        expect(isRtlLocale('en')).toBe(false);
        expect(isRtlLocale('fr')).toBe(false);
    });

    it('formats numbers with the active locale', () => {
        expect(formatNumber(1234.5)).toBe(new Intl.NumberFormat('en').format(1234.5));
        document.documentElement.setAttribute('lang', 'ar');
        expect(formatNumber(1234)).toBe(new Intl.NumberFormat('ar').format(1234));
    });

    it('supports Arabic-Indic digits when requested', () => {
        document.documentElement.setAttribute('lang', 'ar');
        expect(formatNumber(1234, { numberingSystem: 'arab' })).toMatch(/[٠-٩]/);
    });

    it('formats currency and percentages', () => {
        expect(formatCurrency(12.5, 'USD')).toBe('$12.50');
        expect(formatPercent(0.25)).toBe('25%');
        expect(formatPercent(0.256, { maximumFractionDigits: 0 })).toBe('26%');
    });

    it('formats dates and datetimes', () => {
        expect(formatDate('2026-11-02T12:00:00Z')).toContain('2026');
        expect(formatDateTime('2026-11-02T12:00:00Z')).toContain('2026');
    });

    it('formats relative times', () => {
        const now = Date.UTC(2026, 10, 2, 12, 0, 0);
        expect(formatRelative(now - 60 * 60 * 1000, now)).toBe('1 hour ago');
        expect(formatRelative(now - 2 * 24 * 60 * 60 * 1000, now)).toBe('2 days ago');
    });

    it('returns an empty string for invalid input', () => {
        expect(formatNumber('not-a-number')).toBe('');
        expect(formatCurrency(null)).toBe('');
        expect(formatPercent(undefined)).toBe('');
        expect(formatDate('not-a-date')).toBe('');
        expect(formatDateTime(null)).toBe('');
        expect(formatRelative('nope')).toBe('');
    });
});
