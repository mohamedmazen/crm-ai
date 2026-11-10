/* AYDRA — User Documentation behaviour (no dependencies). */
(function () {
    'use strict';

    /* Bilingual model: Arabic lives in /docs/ar/ (RTL), English in /docs/en/ (LTR).
       The language is derived from the current path; the sidebar titles and the
       language switch are localised from here so the same script serves both. */
    var DOCS = [
        { file: '01-getting-started.html', ar: 'البدء السريع', en: 'Getting Started' },
        { file: '02-setup-integrations.html', ar: 'الإعداد والتكاملات (BYOK)', en: 'Setup & Integrations (BYOK)' },
        { file: '03-inbox.html', ar: 'صندوق الوارد والمحادثات', en: 'Inbox & Conversations' },
        { file: '04-contacts.html', ar: 'جهات الاتصال والشرائح', en: 'Contacts & Segments' },
        { file: '05-leads-pipeline.html', ar: 'الليدز وخط الأنابيب', en: 'Leads & Pipeline' },
        { file: '06-sales.html', ar: 'المبيعات والعروض والفواتير', en: 'Sales, Quotes & Invoices' },
        { file: '07-marketing-social.html', ar: 'التسويق والحملات والسوشيال', en: 'Marketing, Campaigns & Social' },
        { file: '08-automation-ai.html', ar: 'الأتمتة والذكاء الاصطناعي', en: 'Automation & AI' },
        { file: '09-reports.html', ar: 'التقارير والتحليلات', en: 'Reports & Analytics' },
        { file: '10-admin-troubleshooting.html', ar: 'إدارة الحساب واستكشاف الأخطاء', en: 'Account Admin & Troubleshooting' },
        { file: '11-whatsapp-coexistence.html', ar: 'تشغيل تطبيق واتساب للأعمال', en: 'WhatsApp Business App (Coexistence)' }
    ];

    var STRINGS = {
        ar: { themeDark: 'الوضع الداكن', themeLight: 'الوضع الفاتح', toEn: 'English', toAr: 'العربية' },
        en: { themeDark: 'Dark mode', themeLight: 'Light mode', toEn: 'English', toAr: 'العربية' }
    };

    var path = location.pathname.toLowerCase();
    var lang = path.indexOf('/en/') !== -1 ? 'en' : 'ar';
    var t = STRINGS[lang];
    var current = (location.pathname.split('/').pop() || 'index.html').toLowerCase();

    /* Keep <html> attributes honest even if a page is authored wrong. */
    document.documentElement.setAttribute('lang', lang);
    document.documentElement.setAttribute('dir', lang === 'en' ? 'ltr' : 'rtl');

    /* ---------- Theme ---------- */
    var root = document.documentElement;
    var stored = null;
    try { stored = localStorage.getItem('aydra-docs-theme'); } catch (e) {}
    if (!stored && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        stored = 'dark';
    }
    if (stored) { root.setAttribute('data-theme', stored); }

    function paintThemeButton() {
        var btn = document.getElementById('theme-toggle');
        if (!btn) { return; }
        var dark = root.getAttribute('data-theme') === 'dark';
        btn.textContent = dark ? '☀️' : '🌙';
        btn.setAttribute('aria-label', dark ? t.themeLight : t.themeDark);
    }

    /* ---------- Language switch ---------- */
    function otherLangHref() {
        /* AR guide -> ../en/<file>; EN guide -> ../ar/<file>; index pages stay on index. */
        var target = lang === 'ar' ? 'en' : 'ar';
        return '../' + target + '/' + current;
    }

    function buildLanguageSwitch() {
        var actions = document.querySelector('.topbar-actions');
        if (!actions || document.getElementById('lang-switch')) { return; }
        var a = document.createElement('a');
        a.id = 'lang-switch';
        a.className = 'btn lang-switch';
        a.href = otherLangHref();
        a.textContent = lang === 'ar' ? t.toEn : t.toAr;
        a.setAttribute('hreflang', lang === 'ar' ? 'en' : 'ar');
        actions.insertBefore(a, actions.firstChild);
    }

    /* ---------- Sidebar navigation ---------- */
    function buildNav() {
        var nav = document.getElementById('doc-nav');
        if (!nav) { return; }
        var html = '';
        DOCS.forEach(function (d, i) {
            var num = String(i + 1).padStart(2, '0');
            var active = d.file.toLowerCase() === current ? ' class="active"' : '';
            html += '<a href="' + d.file + '"' + active + '><span class="num">' + num + '</span><span>' + d[lang] + '</span></a>';
        });
        nav.innerHTML = html;
    }

    /* ---------- Page table of contents ---------- */
    function slugify(text) {
        return text.trim()
            .replace(/[^\p{L}\p{N}\s-]/gu, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .toLowerCase()
            .slice(0, 60) || 'section';
    }

    function buildToc() {
        var list = document.getElementById('page-toc');
        var article = document.querySelector('article');
        if (!list || !article) { return; }

        var used = {};
        var nodes = article.querySelectorAll('h2, h3');
        var html = '';

        nodes.forEach(function (node) {
            var title = node.textContent.trim();
            if (!title) { return; }
            var id = node.id;
            if (!id) {
                id = slugify(title);
                if (used[id]) { id = id + '-' + (++used[id]); } else { used[id] = 1; }
                node.id = id;
            }
            var lvl = node.tagName === 'H2' ? 2 : 3;
            html += '<li class="lvl-' + lvl + '"><a href="#' + id + '">' + title + '</a></li>';
        });

        list.innerHTML = html;

        /* Highlight the section currently in view. */
        var links = Array.prototype.slice.call(list.querySelectorAll('a'));
        if (!links.length || !('IntersectionObserver' in window)) { return; }

        var byId = {};
        links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                links.forEach(function (a) { a.classList.remove('active'); });
                var link = byId[entry.target.id];
                if (link) { link.classList.add('active'); }
            });
        }, { rootMargin: '-80px 0px -70% 0px', threshold: 0 });

        nodes.forEach(function (node) { if (node.id) { observer.observe(node); } });
    }

    /* ---------- Index search ---------- */
    /* Arabic-aware matching: ignores diacritics and spelling variants, then
       strips the article and common plural/possessive endings so that
       "حملة" matches "حملات" and "فواتير" matches "فاتورة". */
    function normalizeArabic(text) {
        return text
            .toLowerCase()
            .replace(/[\u064B-\u0652\u0670\u0640]/g, '')
            .replace(/[أإآٱ]/g, 'ا')
            .replace(/ى/g, 'ي')
            .replace(/ؤ/g, 'و')
            .replace(/ئ/g, 'ي')
            .replace(/ة/g, 'ه');
    }

    function stemArabicToken(token) {
        return token
            .replace(/^ال/, '')
            .replace(/(ات|ون|ين|ان|ه)$/, '');
    }

    function searchable(text) {
        text = normalizeArabic(text);
        return text
            .split(/[^\p{L}\p{N}]+/u)
            .filter(Boolean)
            .map(stemArabicToken)
            .join(' ');
    }

    function wireSearch() {
        var input = document.getElementById('doc-search');
        if (!input) { return; }
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-doc]'));
        var empty = document.getElementById('no-results');

        var index = cards.map(function (card) {
            return searchable(card.getAttribute('data-doc') + ' ' + card.textContent);
        });

        function filter() {
            var q = searchable(input.value.trim());
            var shown = 0;
            cards.forEach(function (card, i) {
                var match = !q || index[i].indexOf(q) !== -1;
                card.classList.toggle('hidden', !match);
                if (match) { shown++; }
            });
            if (empty) { empty.style.display = shown ? 'none' : 'block'; }
        }

        input.addEventListener('input', filter);
    }

    /* ---------- Boot ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        buildLanguageSwitch();
        buildNav();
        buildToc();
        wireSearch();
        paintThemeButton();

        var btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var dark = root.getAttribute('data-theme') === 'dark';
                root.setAttribute('data-theme', dark ? 'light' : 'dark');
                try { localStorage.setItem('aydra-docs-theme', dark ? 'light' : 'dark'); } catch (e) {}
                paintThemeButton();
            });
        }
    });
})();
