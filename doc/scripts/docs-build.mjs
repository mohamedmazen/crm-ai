/**
 * Build the static AYDRA documentation.
 *
 * Run after editing any file under public/docs/{ar,en}:
 *
 *   node doc/scripts/docs-build.mjs
 *
 * It is idempotent and does four things:
 *   1. Rewrites screenshot links from .png to .webp?v=<VERSION>.
 *   2. Adds stable `id` attributes to every <h2>/<h3> so deep links and the
 *      search index keep working (the same slugify() as assets/docs.js).
 *   3. Injects canonical + hreflang + Open Graph/Twitter metadata, and a
 *      "last updated / report an issue" line in the page footer.
 *   4. Writes public/docs/assets/data/search-index.json for the Ctrl+K search.
 */
import { readdirSync, readFileSync, writeFileSync, mkdirSync, statSync } from 'node:fs';
import { join, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const DOCS = join(ROOT, 'public', 'docs');

const SITE = 'https://crm.business-os.tech';
const VERSION = '4';
const LAST_UPDATED = '2026-11-11';
const UPDATED_LABEL = {
    en: 'Last updated 11 November 2026',
    ar: 'آخر تحديث: 11 نوفمبر 2026',
};
const REPORT_LABEL = {
    en: 'Report an issue',
    ar: 'أبلغ عن مشكلة',
};

const LANGS = ['ar', 'en'];

/** Page titles used for metadata, navigation and the search index. */
const MANIFEST = [
    { file: '01-getting-started.html', en: 'Getting Started', ar: 'البدء السريع', descEn: 'Creating the account, choosing a plan, workspaces, roles and the first steps.', descAr: 'إنشاء الحساب واختيار الخطة ومساحات العمل والأدوار والخطوات الأولى.', audEn: 'All users', audAr: 'كل المستخدمين' },
    { file: '02-setup-integrations.html', en: 'Setup & Integrations (BYOK)', ar: 'الإعداد والتكاملات (BYOK)', descEn: 'Connect WhatsApp, Instagram, SMS, email, AI keys, stores and your domain.', descAr: 'اربط واتساب وإنستجرام والرسائل النصية والبريد ومفاتيح الذكاء الاصطناعي والمتاجر والنطاق.', audEn: 'Account admin', audAr: 'مدير الحساب' },
    { file: '03-inbox.html', en: 'Inbox & Conversations', ar: 'صندوق الوارد والمحادثات', descEn: 'Manage conversations across all channels, canned replies, labels and lead conversion.', descAr: 'أدر المحادثات في كل القنوات والردود الجاهزة والتصنيفات وتحويل جهة الاتصال إلى ليد.', audEn: 'Staff / agent', audAr: 'فريق العمل / وكيل' },
    { file: '04-contacts.html', en: 'Contacts & Segments', ar: 'جهات الاتصال والشرائح', descEn: 'Add and import contacts, build segments and custom fields.', descAr: 'أضف جهات الاتصال واستوردها وابنِ الشرائح والحقول المخصصة.', audEn: 'Staff / agent', audAr: 'فريق العمل / وكيل' },
    { file: '05-leads-pipeline.html', en: 'Leads & Pipeline', ar: 'الليدز وخط الأنابيب', descEn: 'Fetch leads, manage deals on the Kanban board, scoring, tasks and companies.', descAr: 'اجلب الليدز وأدر الصفقات على الكانبان والتقييم والمهام والشركات.', audEn: 'Sales team', audAr: 'فريق المبيعات' },
    { file: '06-sales.html', en: 'Sales, Quotes & Invoices', ar: 'المبيعات والعروض والفواتير', descEn: 'Quotes, invoices, products, email sequences, the call log and AI SDR.', descAr: 'العروض والفواتير والمنتجات وتسلسلات البريد وسجل المكالمات وموظف المبيعات الذكي.', audEn: 'Sales team', audAr: 'فريق المبيعات' },
    { file: '07-marketing-social.html', en: 'Marketing, Campaigns & Social', ar: 'التسويق والحملات والسوشيال', descEn: 'WhatsApp, SMS and email campaigns, templates, auto-replies and social posting.', descAr: 'حملات واتساب والرسائل النصية والبريد والقوالب والردود التلقائية والنشر الاجتماعي.', audEn: 'Marketing team', audAr: 'فريق التسويق' },
    { file: '08-automation-ai.html', en: 'Automation & AI', ar: 'الأتمتة والذكاء الاصطناعي', descEn: 'Build automation scenarios, triggers and actions, and configure AI providers and chatbots.', descAr: 'ابنِ سيناريوهات الأتمتة والمشغلات والإجراءات وأعدّ مزوّدي الذكاء الاصطناعي والروبوتات.', audEn: 'Manager / marketing', audAr: 'مدير / تسويق' },
    { file: '09-reports.html', en: 'Reports & Analytics', ar: 'التقارير والتحليلات', descEn: 'Sales, conversations, campaigns, automation, AI and social reports, plus exports.', descAr: 'تقارير المبيعات والمحادثات والحملات والأتمتة والذكاء الاصطناعي والسوشيال والتصدير.', audEn: 'Manager', audAr: 'مدير' },
    { file: '10-admin-troubleshooting.html', en: 'Account Admin & Troubleshooting', ar: 'إدارة الحساب واستكشاف الأخطاء', descEn: 'Team and roles, subscription and billing, the API, security and common problems.', descAr: 'الفريق والأدوار والاشتراك والفواتير وواجهة البرمجة والأمان والمشاكل الشائعة.', audEn: 'Account admin', audAr: 'مدير الحساب' },
    { file: '11-roles-permissions.html', en: 'Roles & Permissions', ar: 'الأدوار والصلاحيات', descEn: 'Create custom roles, choose view and edit permissions per module, and assign them to the team.', descAr: 'أنشئ أدوارًا مخصصة وحدّد صلاحيات العرض والإنشاء والتعديل لكل وحدة ثم عيّنها للفريق.', audEn: 'Account admin', audAr: 'مدير الحساب' },
    { file: '11-whatsapp-coexistence.html', en: 'WhatsApp Business App (Coexistence)', ar: 'تشغيل تطبيق واتساب للأعمال', descEn: 'Connect your existing WhatsApp Business app number while the app keeps working.', descAr: 'اربط رقم تطبيق واتساب للأعمال الحالي مع استمرار عمل التطبيق.', audEn: 'Account admin', audAr: 'مدير الحساب' },
    { file: '12-glossary.html', en: 'Glossary', ar: 'المصطلحات', descEn: 'Every AYDRA term in one place: contacts, leads, stages, campaigns, sequences, agents and plans.', descAr: 'كل مصطلحات AYDRA في مكان واحد: جهات الاتصال والليدز والمراحل والحملات والتسلسلات والوكلاء والخطط.', audEn: 'All users', audAr: 'كل المستخدمين' },
];

const MANIFEST_BY_FILE = Object.fromEntries(MANIFEST.map((m) => [m.file, m]));

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/"/g, '&quot;');
}

function stripTags(html) {
    return html
        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/\s+/g, ' ')
        .trim();
}

/** Must match slugify() in public/docs/assets/docs.js. */
function slugify(text) {
    return text.trim()
        .replace(/[^\p{L}\p{N}\s-]/gu, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .toLowerCase()
        .slice(0, 60) || 'section';
}

function ensureHeadingIds(html) {
    const used = {};
    return html.replace(/<h([23])([^>]*)>([\s\S]*?)<\/h\1>/gi, (match, level, attrs, inner) => {
        if (/\bid\s*=/.test(attrs)) { return match; }
        const text = stripTags(inner);
        if (!text) { return match; }
        let id = slugify(text);
        if (used[id]) { id = id + '-' + (++used[id]); } else { used[id] = 1; }
        return `<h${level}${attrs} id="${id}">${inner}</h${level}>`;
    });
}

function firstImage(html) {
    const match = html.match(/assets\/img\/(ar|en)\/([a-z0-9-]+)\.(?:png|webp)/i);
    if (!match) { return null; }
    return { lang: match[1], slug: match[2] };
}

function metaBlock({ lang, otherLang, file, title, description, hero }) {
    const path = `/docs/${lang}/${file}`;
    const otherPath = `/docs/${otherLang}/${file}`;
    const url = SITE + path;
    const otherUrl = SITE + otherPath;
    const image = hero ? `${SITE}/docs/assets/img/${hero.lang}/${hero.slug}.webp` : `${SITE}/docs/assets/img/${lang}/dashboard.webp`;
    const locale = lang === 'ar' ? 'ar_AR' : 'en_US';
    const altLocale = lang === 'ar' ? 'en_US' : 'ar_AR';
    const localeName = lang === 'ar' ? 'ar' : 'en';

    return [
        '<!-- docs:meta -->',
        `<link rel="canonical" href="${escapeAttr(url)}">`,
        `<link rel="alternate" hreflang="ar" href="${escapeAttr(SITE + '/docs/ar/' + file)}">`,
        `<link rel="alternate" hreflang="en" href="${escapeAttr(SITE + '/docs/en/' + file)}">`,
        `<link rel="alternate" hreflang="x-default" href="${escapeAttr(SITE + '/docs/en/' + file)}">`,
        `<meta property="og:type" content="article">`,
        `<meta property="og:site_name" content="AYDRA">`,
        `<meta property="og:title" content="${escapeAttr(title)}">`,
        `<meta property="og:description" content="${escapeAttr(description)}">`,
        `<meta property="og:url" content="${escapeAttr(url)}">`,
        `<meta property="og:locale" content="${locale}">`,
        `<meta property="og:locale:alternate" content="${altLocale}">`,
        `<meta property="og:image" content="${escapeAttr(image)}">`,
        `<meta property="og:image:alt" content="${escapeAttr(title)}">`,
        `<meta name="twitter:card" content="summary_large_image">`,
        `<meta name="twitter:title" content="${escapeAttr(title)}">`,
        `<meta name="twitter:description" content="${escapeAttr(description)}">`,
        `<meta name="twitter:image" content="${escapeAttr(image)}">`,
        `<meta name="docs:lang" content="${localeName}">`,
        `<meta name="docs:updated" content="${LAST_UPDATED}">`,
        '<!-- /docs:meta -->',
    ].join('\n');
}

function applyMeta(html, context) {
    const cleaned = html.replace(/\n?<!-- docs:meta -->[\s\S]*?<!-- \/docs:meta -->\n?/g, '\n');
    const block = metaBlock(context);
    if (/<\/head>/i.test(cleaned)) {
        return cleaned.replace(/<\/head>/i, `${block}\n</head>`);
    }
    return cleaned;
}

function applyFooter(html, { lang, file }) {
    const reportHref = '/app/support';
    const meta = `<span class="updated">${UPDATED_LABEL[lang]} · <a href="${reportHref}" target="_blank" rel="noopener">${REPORT_LABEL[lang]}</a></span>`;
    if (/<footer class="doc-footer"/.test(html)) {
        if (/class="updated"/.test(html)) {
            return html.replace(/<span class="updated">[\s\S]*?<\/span>/, meta);
        }
        return html.replace(/(<footer class="doc-footer"[^>]*>)/, `$1\n            ${meta}`);
    }
    return html;
}

function upgradeAssets(html) {
    // Images: png -> webp, cache-busted.
    html = html.replace(/(assets\/img\/(?:ar|en)\/[a-z0-9-]+)\.png(\?[^"']*)?/gi, `$1.webp?v=${VERSION}`);
    // Keep already-WebP links on the current cache version.
    html = html.replace(/(assets\/img\/(?:ar|en)\/[a-z0-9-]+\.webp)\?v=\d+/gi, `$1?v=${VERSION}`);
    // CSS/JS cache busting.
    html = html.replace(/(assets\/(?:docs\.css|docs\.js))\?v=\d+/g, `$1?v=${VERSION}`);
    // Lazy-load every screenshot and reserve its box so the layout does not
    // jump while the image downloads. Capture sizes: desktop 1600x1000,
    // mobile 390x844.
    html = html.replace(/<img\b([^>]*?)>/gi, (match, attrs) => {
        const srcMatch = attrs.match(/\bsrc\s*=\s*"([^"]+)"/i);
        let extra = '';
        if (srcMatch && /assets\/img\/(?:ar|en)\//.test(srcMatch[1])) {
            const mobile = /-mobile\.webp/i.test(srcMatch[1]);
            if (!/\bwidth\s*=/.test(attrs)) { extra += ` width="${mobile ? 390 : 1600}"`; }
            if (!/\bheight\s*=/.test(attrs)) { extra += ` height="${mobile ? 844 : 1000}"`; }
        }
        if (!/\bloading\s*=/.test(attrs)) { extra += ' loading="lazy"'; }
        if (!extra) { return match; }
        return `<img${attrs}${extra}>`;
    });
    return html;
}

function buildSearchIndex() {
    const index = [];

    for (const lang of LANGS) {
        for (const entry of MANIFEST) {
            const filePath = join(DOCS, lang, entry.file);
            if (!statSync(filePath, { throwIfNoEntry: false })) { continue; }
            const html = readFileSync(filePath, 'utf8');
            const articleMatch = html.match(/<article>([\s\S]*?)<\/article>/i);
            const article = articleMatch ? articleMatch[1] : html;
            const pageTitle = entry[lang];
            const h1Match = article.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);
            const leadMatch = article.match(/<p class="lead"[^>]*>([\s\S]*?)<\/p>/i);

            index.push({
                lang,
                page: entry.file,
                id: '',
                level: 1,
                title: pageTitle,
                guide: pageTitle,
                text: stripTags((h1Match ? h1Match[1] : '') + ' ' + (leadMatch ? leadMatch[1] : '')).slice(0, 280),
            });

            const headingRe = /<h([23])([^>]*)>([\s\S]*?)<\/h\1>/gi;
            const matches = [];
            let match;
            while ((match = headingRe.exec(article)) !== null) {
                matches.push({
                    level: Number(match[1]),
                    attrs: match[2],
                    inner: match[3],
                    start: match.index,
                    end: headingRe.lastIndex,
                });
            }

            for (let i = 0; i < matches.length; i++) {
                const m = matches[i];
                const idMatch = m.attrs.match(/\bid\s*=\s*"([^"]+)"/i);
                if (!idMatch) { continue; }
                const nextStart = i + 1 < matches.length ? matches[i + 1].start : article.length;
                const body = stripTags(article.slice(m.end, nextStart));
                const title = stripTags(m.inner);
                index.push({
                    lang,
                    page: entry.file,
                    id: idMatch[1],
                    level: m.level,
                    title,
                    guide: pageTitle,
                    text: (title + ' ' + body).slice(0, 420),
                });
            }
        }
    }

    const outDir = join(DOCS, 'assets', 'data');
    mkdirSync(outDir, { recursive: true });
    writeFileSync(join(outDir, 'search-index.json'), JSON.stringify(index, null, 2));
    return index;
}

function buildPage({ lang, file }) {
    const filePath = join(DOCS, lang, file);
    if (!statSync(filePath, { throwIfNoEntry: false })) { return null; }

    let html = readFileSync(filePath, 'utf8');
    const otherLang = lang === 'ar' ? 'en' : 'ar';
    const entry = MANIFEST_BY_FILE[file];
    const title = entry ? `${entry[lang]} · AYDRA` : 'AYDRA User Guides';
    const description = entry ? (lang === 'ar' ? entry.descAr : entry.descEn) : '';

    const hero = firstImage(html);

    html = upgradeAssets(html);
    html = ensureHeadingIds(html);
    html = applyMeta(html, { lang, otherLang, file, title, description, hero });
    html = applyFooter(html, { lang, file });

    writeFileSync(filePath, html);
    return { lang, file, hero, bytes: Buffer.byteLength(html) };
}

function buildIndexPages() {
    for (const lang of LANGS) {
        const filePath = join(DOCS, lang, 'index.html');
        if (!statSync(filePath, { throwIfNoEntry: false })) { continue; }
        let html = readFileSync(filePath, 'utf8');
        const otherLang = lang === 'ar' ? 'en' : 'ar';
        html = upgradeAssets(html);
        html = applyMeta(html, {
            lang,
            otherLang,
            file: 'index.html',
            title: lang === 'ar' ? 'أدلة المستخدم · AYDRA' : 'User Guides · AYDRA',
            description: lang === 'ar'
                ? 'أدلة مستخدم AYDRA: الإعداد وصندوق الوارد وجهات الاتصال والليدز والمبيعات والتسويق والأتمتة والتقارير والإدارة والأدوار والصلاحيات وتشغيل واتساب والمصطلحات.'
                : 'AYDRA user manuals: setup, inbox, contacts, leads, sales, marketing, automation, reports, account admin, roles and permissions, WhatsApp coexistence and a glossary.',
            hero: { lang, slug: 'dashboard' },
        });
        // Index pages should not advertise themselves as needing cache busting.
        writeFileSync(filePath, html);
    }
}

function main() {
    const results = [];
    for (const lang of LANGS) {
        const files = readdirSync(join(DOCS, lang))
            .filter((f) => f.endsWith('.html') && f !== 'index.html')
            .sort();
        for (const file of files) {
            const result = buildPage({ lang, file });
            if (result) { results.push(result); }
        }
    }
    buildIndexPages();
    const index = buildSearchIndex();

    console.log(`Built ${results.length} guide pages.`);
    console.log(`Search index: ${index.length} entries.`);
    for (const r of results) {
        console.log(`  ${r.lang}/${r.file} (${Math.round(r.bytes / 1024)} KB)`);
    }
}

main();
