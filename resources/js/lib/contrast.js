/**
 * Contrast helpers for colours the user picks at runtime (inbox labels, contact
 * tags). A chip cannot hard-code white text when the background is an arbitrary
 * hex, so it asks this module which of black/white clears WCAG's 4.5:1 floor —
 * one of the two always does.
 */

/** Parse `#rgb`, `#rrggbb` or `rgb(r, g, b)` into `[r, g, b]` (0–255). */
export function parseColor(color) {
    if (typeof color !== 'string') {
        return null;
    }

    const value = color.trim();
    const hex = value.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);

    if (hex) {
        const digits = hex[1].length === 3
            ? hex[1].split('').map((d) => d + d).join('')
            : hex[1];

        return [
            parseInt(digits.slice(0, 2), 16),
            parseInt(digits.slice(2, 4), 16),
            parseInt(digits.slice(4, 6), 16),
        ];
    }

    const rgb = value.match(/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i);

    return rgb ? [Number(rgb[1]), Number(rgb[2]), Number(rgb[3])] : null;
}

/** WCAG 2.1 relative luminance (0 = black, 1 = white). */
export function relativeLuminance([r, g, b]) {
    const channel = (value) => {
        const c = value / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/** WCAG 2.1 contrast ratio between two colours (1–21). */
export function contrastRatio(first, second) {
    const a = parseColor(first);
    const b = parseColor(second);

    if (!a || !b) {
        return 1;
    }

    const [lighter, darker] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x);

    return (lighter + 0.05) / (darker + 0.05);
}

/** `#ffffff` or `#000000`, whichever reads better on the given background. */
export function readableTextColor(background, minimum = 4.5) {
    const white = contrastRatio(background, '#ffffff');

    return white >= minimum ? '#ffffff' : '#000000';
}
