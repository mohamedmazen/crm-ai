/**
 * Static checks for the AYDRA documentation.
 *
 *   node doc/scripts/docs-check.mjs
 *
 * Runs in CI: fails on missing screenshots, broken internal links, missing
 * heading ids, Arabic/English file drift, missing annotations, or oversized
 * images. It does not need PHP, a database or a browser.
 */
import { readdirSync, readFileSync, existsSync, statSync } from 'node:fs';
import { join, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const DOCS = join(ROOT, 'public', 'docs');
const LANGS = ['ar', 'en'];
const IMAGE_BUDGET = 260 * 1024;
const TOTAL_BUDGET = 14 * 1024 * 1024;

const errors = [];
const warnings = [];

function fail(message) {
    errors.push(message);
}

function warn(message) {
    warnings.push(message);
}

function htmlFiles(lang) {
    return readdirSync(join(DOCS, lang))
        .filter((file) => file.endsWith('.html'))
        .sort();
}

function stripQuery(value) {
    return value.split('?')[0].split('#')[0];
}

function checkFileSet() {
    const ar = new Set(htmlFiles('ar'));
    const en = new Set(htmlFiles('en'));

    for (const file of ar) {
        if (!en.has(file)) { fail(`en/${file} is missing (exists in ar/)`); }
    }
    for (const file of en) {
        if (!ar.has(file)) { fail(`ar/${file} is missing (exists in en/)`); }
    }
}

function checkPage(lang, file) {
    const filePath = join(DOCS, lang, file);
    const html = readFileSync(filePath, 'utf8');
    const label = `${lang}/${file}`;

    /* Screenshots must exist and must not point at the retired PNG files. */
    const imgRe = /<img\b([^>]*)>/gi;
    let img;
    while ((img = imgRe.exec(html)) !== null) {
        const attrs = img[1];
        const srcMatch = attrs.match(/\bsrc\s*=\s*"([^"]+)"/i);
        if (!srcMatch) { fail(`${label}: <img> without src`); continue; }
        const src = stripQuery(srcMatch[1]);
        if (/\.png$/i.test(src)) { fail(`${label}: PNG screenshot reference ${src}`); }
        const resolved = join(DOCS, lang, src.replace(/^\.\//, ''));
        if (!existsSync(resolved)) { fail(`${label}: missing image ${src}`); }
        if (!/\balt\s*=\s*"[^"]+"/i.test(attrs)) { warn(`${label}: image without meaningful alt (${src})`); }
    }

    /* Internal guide links must resolve. */
    const linkRe = /<a\b[^>]*\bhref\s*=\s*"([^"]+)"/gi;
    let link;
    while ((link = linkRe.exec(html)) !== null) {
        const href = link[1];
        if (/^(https?:|mailto:|tel:|\/|#)/i.test(href)) { continue; }
        const target = stripQuery(href);
        if (!/\.html$/i.test(target)) { continue; }
        const resolved = join(DOCS, lang, target);
        if (!existsSync(resolved)) { fail(`${label}: broken link ${href}`); }
    }

    /* Every heading needs an id for deep links and the search index. Index
       pages use <h2> only inside navigation cards, so they are exempt. */
    if (file !== 'index.html') {
        const headingRe = /<h([23])([^>]*)>/gi;
        let heading;
        while ((heading = headingRe.exec(html)) !== null) {
            if (!/\bid\s*=\s*"/.test(heading[2])) {
                fail(`${label}: <h${heading[1]}> without id`);
            }
        }
    }

    /* Figures must be real screenshots. */
    const figureRe = /<figure class="shot"[\s\S]*?<\/figure>/gi;
    let figure;
    while ((figure = figureRe.exec(html)) !== null) {
        if (!/<img\b/i.test(figure[0])) { fail(`${label}: figure.shot without an image`); }
    }

    return html;
}

function checkSearchIndex() {
    const filePath = join(DOCS, 'assets', 'data', 'search-index.json');
    if (!existsSync(filePath)) { fail('assets/data/search-index.json is missing — run docs:build'); return; }

    let index;
    try { index = JSON.parse(readFileSync(filePath, 'utf8')); } catch (e) {
        fail('assets/data/search-index.json is not valid JSON');
        return;
    }

    if (!Array.isArray(index) || index.length < 50) {
        fail('search-index.json looks empty');
        return;
    }

    for (const lang of LANGS) {
        const pages = new Set(index.filter((entry) => entry.lang === lang).map((entry) => entry.page));
        for (const file of htmlFiles(lang)) {
            if (file === 'index.html') { continue; }
            if (!pages.has(file)) { fail(`search-index.json has no entries for ${lang}/${file}`); }
        }
    }
}

function checkAnnotations() {
    const filePath = join(DOCS, 'assets', 'data', 'annotations.json');
    if (!existsSync(filePath)) {
        warn('assets/data/annotations.json is missing — screenshots will render without badges');
        return;
    }

    let data;
    try { data = JSON.parse(readFileSync(filePath, 'utf8')); } catch (e) {
        fail('assets/data/annotations.json is not valid JSON');
        return;
    }

    for (const lang of LANGS) {
        for (const [slug, points] of Object.entries(data[lang] || {})) {
            if (!Array.isArray(points) || !points.length) {
                fail(`annotations.json: ${lang}/${slug} has no points`);
                continue;
            }
            for (const point of points) {
                if (typeof point.x !== 'number' || typeof point.y !== 'number') {
                    fail(`annotations.json: ${lang}/${slug} point without numeric x/y`);
                }
                if (point.x < 0 || point.x > 100 || point.y < 0 || point.y > 100) {
                    fail(`annotations.json: ${lang}/${slug} point outside the image (${point.x}, ${point.y})`);
                }
                if (!point.label) { warn(`annotations.json: ${lang}/${slug} point without a label`); }
            }
        }
    }
}

function checkImageBudget() {
    let total = 0;
    for (const lang of LANGS) {
        const dir = join(DOCS, 'assets', 'img', lang);
        if (!existsSync(dir)) { fail(`image folder missing: assets/img/${lang}`); continue; }
        for (const file of readdirSync(dir)) {
            if (!/\.(webp|png)$/i.test(file)) { continue; }
            const size = statSync(join(dir, file)).size;
            total += size;
            if (size > IMAGE_BUDGET) {
                warn(`assets/img/${lang}/${file} is ${Math.round(size / 1024)} KB (budget ${Math.round(IMAGE_BUDGET / 1024)} KB)`);
            }
        }
    }
    if (total > TOTAL_BUDGET) {
        fail(`screenshots total ${(total / 1024 / 1024).toFixed(1)} MB, over the ${(TOTAL_BUDGET / 1024 / 1024).toFixed(0)} MB budget`);
    }
    return total;
}

function checkScriptsAndVersions() {
    for (const file of ['docs.js', 'docs.css']) {
        if (!existsSync(join(DOCS, 'assets', file))) { fail(`assets/${file} is missing`); }
    }

    /* Every guide must load the versioned CSS/JS pair. */
    for (const lang of LANGS) {
        for (const file of htmlFiles(lang)) {
            if (file === 'index.html') { continue; }
            const html = readFileSync(join(DOCS, lang, file), 'utf8');
            if (!/assets\/docs\.css\?v=\d+/.test(html)) { fail(`${lang}/${file}: docs.css is not cache-busted`); }
            if (!/assets\/docs\.js\?v=\d+/.test(html)) { fail(`${lang}/${file}: docs.js is not cache-busted`); }
        }
    }
}

function main() {
    checkFileSet();

    for (const lang of LANGS) {
        for (const file of htmlFiles(lang)) {
            checkPage(lang, file);
        }
    }

    checkSearchIndex();
    checkAnnotations();
    const total = checkImageBudget();
    checkScriptsAndVersions();

    for (const message of warnings) { console.log(`  warn  ${message}`); }
    for (const message of errors) { console.log(`  error ${message}`); }

    console.log(`\n${errors.length} error(s), ${warnings.length} warning(s) · ${(total / 1024 / 1024).toFixed(2)} MB of screenshots`);

    if (errors.length) { process.exit(1); }
}

main();
