/* AYDRA — User Documentation behaviour (no dependencies). */
(function () {
    'use strict';

    var DOCS = [
        { file: '01-getting-started.html', title: 'البدء السريع' },
        { file: '02-setup-integrations.html', title: 'الإعداد والتكاملات (BYOK)' },
        { file: '03-inbox.html', title: 'صندوق الوارد والمحادثات' },
        { file: '04-contacts.html', title: 'جهات الاتصال والشرائح' },
        { file: '05-leads-pipeline.html', title: 'الليدز وخط الأنابيب' },
        { file: '06-sales.html', title: 'المبيعات والعروض والفواتير' },
        { file: '07-marketing-social.html', title: 'التسويق والحملات والسوشيال' },
        { file: '08-automation-ai.html', title: 'الأتمتة والذكاء الاصطناعي' },
        { file: '09-reports.html', title: 'التقارير والتحليلات' },
        { file: '10-admin-troubleshooting.html', title: 'إدارة الحساب واستكشاف الأخطاء' }
    ];

    var current = (location.pathname.split('/').pop() || 'index.html').toLowerCase();

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
        btn.setAttribute('aria-label', dark ? 'الوضع الفاتح' : 'الوضع الداكن');
    }

    /* ---------- Sidebar navigation ---------- */
    function buildNav() {
        var nav = document.getElementById('doc-nav');
        if (!nav) { return; }
        var html = '';
        DOCS.forEach(function (d, i) {
            var num = String(i + 1).padStart(2, '0');
            var active = d.file.toLowerCase() === current ? ' class="active"' : '';
            html += '<a href="' + d.file + '"' + active + '><span class="num">' + num + '</span><span>' + d.title + '</span></a>';
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

    function stemToken(token) {
        return token
            .replace(/^ال/, '')
            .replace(/(ات|ون|ين|ان|ه)$/, '');
    }

    function searchable(text) {
        return normalizeArabic(text)
            .split(/[^\p{L}\p{N}]+/u)
            .filter(Boolean)
            .map(stemToken)
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
