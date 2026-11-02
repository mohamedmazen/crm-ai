import { usePage } from '@inertiajs/react';

// Bundled lockup, used when the admin hasn't uploaded a logo. There are two
// files because the wordmark has to invert: dark text on light surfaces, white
// text on dark ones.
const BUILT_IN = {
    light: '/brand/aydra-logo-light.png',
    dark: '/brand/aydra-logo-dark.png',
};

/**
 * The app logo.
 *
 * An uploaded branding logo always wins. Otherwise the bundled AYDRA lockup is
 * rendered: pass `tone="dark"` on always-dark surfaces (sidebar, landing header,
 * auth hero), `tone="light"` on always-light ones, or leave it on "auto" to let
 * the theme decide.
 */
export default function ApplicationLogo({ className, style, alt, tone = 'auto' }) {
    let logoUrl = null;
    try {
        // eslint-disable-next-line react-hooks/rules-of-hooks
        logoUrl = usePage().props.branding?.logo_url ?? null;
    } catch {
        logoUrl = null;
    }

    // Callers size the logo as a glyph (e.g. "h-9 w-9"), but the artwork is a
    // wide wordmark: keep the caller's height/max bounds, let width follow the
    // aspect ratio, and drop the paint classes (they only apply to the old SVG
    // fallback).
    const imgClassName = [
        ...String(className ?? '')
            .split(/\s+/)
            .filter(Boolean)
            // Compare on the utility itself so variants (dark:, lg:) are matched
            // too. `max-w-*` survives — only a fixed `w-*` would force the ratio.
            .filter((c) => !/^(w|fill|text)-/.test(c.split(':').pop())),
        'w-auto',
        'object-contain',
    ].join(' ');

    if (logoUrl) {
        return (
            <img
                src={logoUrl}
                alt={alt ?? 'App Logo'}
                className={imgClassName}
                style={style}
            />
        );
    }

    if (tone !== 'auto') {
        return (
            <img
                src={BUILT_IN[tone === 'dark' ? 'dark' : 'light']}
                alt={alt ?? 'App Logo'}
                className={imgClassName}
                style={style}
            />
        );
    }

    // "auto": both files are shipped and CSS picks one, so the right artwork is
    // already painted before hydration (no flash of the wrong wordmark).
    return (
        <>
            <img
                src={BUILT_IN.light}
                alt={alt ?? 'App Logo'}
                className={`${imgClassName} dark:hidden`}
                style={style}
            />
            <img
                src={BUILT_IN.dark}
                alt=""
                aria-hidden="true"
                className={`${imgClassName} hidden dark:block`}
                style={style}
            />
        </>
    );
}