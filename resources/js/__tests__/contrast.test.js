import { describe, it, expect } from 'vitest';
import { parseColor, relativeLuminance, contrastRatio, readableTextColor } from '@/lib/contrast';

// ─── parseColor ─────────────────────────────────────────────────────────────

describe('parseColor', () => {
    it('parses #rrggbb', () => {
        expect(parseColor('#467235')).toEqual([70, 114, 53]);
    });

    it('expands #rgb shorthand', () => {
        expect(parseColor('#fff')).toEqual([255, 255, 255]);
    });

    it('parses rgb() and rgba()', () => {
        expect(parseColor('rgb(70, 114, 53)')).toEqual([70, 114, 53]);
        expect(parseColor('rgba(70, 114, 53, 0.5)')).toEqual([70, 114, 53]);
    });

    it('returns null for unsupported input', () => {
        expect(parseColor('not-a-color')).toBeNull();
        expect(parseColor(undefined)).toBeNull();
    });
});

// ─── contrastRatio ──────────────────────────────────────────────────────────

describe('contrastRatio', () => {
    it('is 21 for black on white', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 1);
    });

    it('is 1 for identical colours', () => {
        expect(contrastRatio('#467235', '#467235')).toBeCloseTo(1, 5);
    });

    it('is symmetric', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(contrastRatio('#ffffff', '#000000'), 5);
    });
});

// ─── relativeLuminance ──────────────────────────────────────────────────────

describe('relativeLuminance', () => {
    it('is 0 for black and 1 for white', () => {
        expect(relativeLuminance([0, 0, 0])).toBeCloseTo(0, 5);
        expect(relativeLuminance([255, 255, 255])).toBeCloseTo(1, 5);
    });
});

// ─── readableTextColor ──────────────────────────────────────────────────────

describe('readableTextColor', () => {
    it('picks white on a dark brand colour', () => {
        expect(readableTextColor('#467235')).toBe('#ffffff');
    });

    it('picks black on a light brand colour', () => {
        expect(readableTextColor('#dcf8c6')).toBe('#000000');
    });

    it('always clears the requested minimum contrast', () => {
        const colours = ['#467235', '#25D366', '#f04e2e', '#0084FF', '#E1306C', '#F59E0B', '#ffffff', '#000000'];

        for (const colour of colours) {
            expect(contrastRatio(colour, readableTextColor(colour))).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('honours a custom minimum threshold', () => {
        // A mid grey (~3.45:1 against white) clears a laxer 3:1 floor but not 4.5:1.
        expect(readableTextColor('#8a8a8a', 3)).toBe('#ffffff');
        expect(readableTextColor('#8a8a8a', 4.5)).toBe('#000000');
    });
});
