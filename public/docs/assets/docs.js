/* AYDRA — User Documentation behaviour (no dependencies). */
(function () {
    'use strict';

    /* Bilingual model: Arabic lives in /docs/ar/ (RTL), English in /docs/en/ (LTR).
       The language is derived from the current path; the sidebar titles, the
       language switch and all runtime strings are localised from here. */
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
        { file: '11-roles-permissions.html', ar: 'الأدوار والصلاحيات', en: 'Roles & Permissions' },
        { file: '11-whatsapp-coexistence.html', ar: 'تشغيل تطبيق واتساب للأعمال', en: 'WhatsApp Business App (Coexistence)' },
        { file: '12-glossary.html', ar: 'المصطلحات', en: 'Glossary' }
    ];

    var STRINGS = {
        ar: {
            themeDark: 'الوضع الداكن', themeLight: 'الوضع الفاتح', toEn: 'English', toAr: 'العربية',
            search: 'بحث', searchPlaceholder: 'ابحث في الأدلة…', searchHint: 'Enter لفتح أول نتيجة · Esc لإغلاق',
            searchEmpty: 'لا توجد نتائج مطابقة. جرّب كلمة أخرى مثل «واتساب» أو «فاتورة».',
            searchNoIndex: 'تعذّر تحميل فهرس البحث.',
            guide: 'دليل'
        },
        en: {
            themeDark: 'Dark mode', themeLight: 'Light mode', toEn: 'English', toAr: 'العربية',
            search: 'Search', searchPlaceholder: 'Search the guides…', searchHint: 'Enter opens the first result · Esc closes',
            searchEmpty: 'No matching results. Try another word such as “WhatsApp” or “invoice”.',
            searchNoIndex: 'Could not load the search index.',
            guide: 'Guide'
        }
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
        return '../' + target + '/' + current + location.hash;
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

        /* Highlight the section currently in view. A scroll handler is used
           instead of IntersectionObserver so the state is correct in every
           browser, embed and background tab. */
        var links = Array.prototype.slice.call(list.querySelectorAll('a'));
        if (!links.length) { return; }

        var headings = Array.prototype.slice.call(nodes).filter(function (node) { return !!node.id; });
        if (!headings.length) { return; }

        function highlight() {
            var offset = 110;
            var currentId = headings[0].id;
            headings.forEach(function (node) {
                if (node.getBoundingClientRect().top <= offset) { currentId = node.id; }
            });
            links.forEach(function (a) {
                a.classList.toggle('active', a.getAttribute('href') === '#' + currentId);
            });
        }

        var lastRun = 0;
        window.addEventListener('scroll', function () {
            var now = Date.now();
            if (now - lastRun < 60) { return; }
            lastRun = now;
            highlight();
        }, { passive: true });
        highlight();
    }

    /* ---------- Shared text matching (Arabic-aware) ---------- */
    /* Ignores diacritics and spelling variants, then strips the article and
       common plural/possessive endings so that "حملة" matches "حملات" and
       "فواتير" matches "فاتورة". */
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

    function tokenize(text) {
        return normalizeArabic(text)
            .split(/[^\p{L}\p{N}]+/u)
            .filter(Boolean)
            .map(stemArabicToken);
    }

    function searchable(text) {
        return tokenize(text).join(' ');
    }

    /* ---------- Annotation badges ---------- */
    /* Coordinates come from doc/scripts/docs-capture.mjs, which probes the live
       app and writes public/docs/assets/data/annotations.json. Each badge is a
       percentage position over the screenshot so it scales with the page. */
    function imageSlug(img) {
        var src = img.getAttribute('src') || '';
        var match = src.match(/img\/(?:ar|en)\/([a-z0-9-]+)\.(?:webp|png)/i);
        return match ? match[1] : null;
    }

    function buildAnnotation(container, points) {
        points.forEach(function (point, index) {
            var badge = document.createElement('span');
            badge.className = 'anno-badge';
            badge.style.left = point.x + '%';
            badge.style.top = point.y + '%';
            badge.textContent = String(index + 1);
            badge.tabIndex = 0;
            var label = point.label || '';
            badge.setAttribute('aria-label', label);
            if (label) {
                var tip = document.createElement('span');
                tip.className = 'anno-tip';
                tip.textContent = label;
                badge.appendChild(tip);
            }
            container.appendChild(badge);
        });
    }

    function applyAnnotations() {
        var figures = document.querySelectorAll('figure.shot');
        if (!figures.length) { return; }

        fetch('../assets/data/annotations.json')
            .then(function (response) { return response.ok ? response.json() : null; })
            .catch(function () { return null; })
            .then(function (data) {
                if (!data) { return; }
                var pointsFor = data[lang] || {};

                Array.prototype.forEach.call(figures, function (figure) {
                    var img = figure.querySelector('img');
                    if (!img) { return; }

                    var slug = figure.getAttribute('data-slug') || imageSlug(img);
                    if (!slug) { return; }

                    var points = null;
                    if (figure.hasAttribute('data-points')) {
                        try { points = JSON.parse(figure.getAttribute('data-points')); } catch (e) { points = null; }
                    }
                    points = points || pointsFor[slug];
                    if (!points || !points.length) { return; }

                    var anchor = img.parentNode; /* the <a> around the image */
                    var canvas = document.createElement('div');
                    canvas.className = 'shot-canvas';
                    anchor.parentNode.insertBefore(canvas, anchor);
                    canvas.appendChild(anchor);
                    buildAnnotation(canvas, points);
                });
            });
    }

    /* ---------- Global search (Ctrl/Cmd+K) ---------- */
    var searchIndex = null;
    var searchIndexPromise = null;

    function loadSearchIndex() {
        if (searchIndexPromise) { return searchIndexPromise; }
        searchIndexPromise = fetch('../assets/data/search-index.json')
            .then(function (response) { return response.ok ? response.json() : []; })
            .catch(function () { return []; })
            .then(function (items) {
                searchIndex = (items || []).filter(function (item) { return item.lang === lang; });
                return searchIndex;
            });
        return searchIndexPromise;
    }

    function buildSearchModal() {
        if (document.getElementById('search-modal')) { return; }

        var modal = document.createElement('div');
        modal.id = 'search-modal';
        modal.className = 'search-modal';
        modal.hidden = true;
        modal.innerHTML =
            '<div class="search-panel" role="dialog" aria-modal="true" aria-label="' + t.search + '">' +
                '<input id="search-modal-input" type="search" autocomplete="off" placeholder="' + t.searchPlaceholder + '" aria-label="' + t.searchPlaceholder + '">' +
                '<div id="search-modal-results" class="search-results"></div>' +
                '<div class="search-hint">' + t.searchHint + '</div>' +
            '</div>';
        document.body.appendChild(modal);

        var input = modal.querySelector('#search-modal-input');
        var results = modal.querySelector('#search-modal-results');

        function close() {
            modal.hidden = true;
            document.body.classList.remove('search-open');
        }

        function open() {
            modal.hidden = false;
            document.body.classList.add('search-open');
            input.value = '';
            results.innerHTML = '';
            loadSearchIndex().then(function () { input.focus(); });
        }

        function render() {
            var query = input.value.trim();
            var tokens = tokenize(query);
            if (!tokens.length) { results.innerHTML = ''; return; }

            loadSearchIndex().then(function (items) {
                if (!items.length) {
                    results.innerHTML = '<p class="search-empty">' + t.searchNoIndex + '</p>';
                    return;
                }

                var scored = [];
                items.forEach(function (item) {
                    var title = searchable(item.title || '');
                    var body = searchable(item.text || '');
                    var score = 0;
                    var matched = true;
                    tokens.forEach(function (token) {
                        var inTitle = title.indexOf(token) !== -1;
                        var inBody = body.indexOf(token) !== -1;
                        if (!inTitle && !inBody) { matched = false; return; }
                        score += inTitle ? 10 : 1;
                    });
                    if (!matched) { return; }
                    if (query && (item.title || '').toLowerCase().indexOf(query.toLowerCase()) !== -1) { score += 20; }
                    scored.push({ item: item, score: score });
                });

                scored.sort(function (a, b) { return b.score - a.score; });

                if (!scored.length) {
                    results.innerHTML = '<p class="search-empty">' + t.searchEmpty + '</p>';
                    return;
                }

                results.innerHTML = scored.slice(0, 14).map(function (entry) {
                    var item = entry.item;
                    var href = item.page + (item.id ? '#' + item.id : '');
                    var snippet = (item.text || '').slice(0, 150);
                    if ((item.text || '').length > 150) { snippet += '…'; }
                    return '<a class="search-result" href="' + href + '">' +
                        '<span class="sr-guide">' + item.guide + '</span>' +
                        '<span class="sr-title">' + item.title + '</span>' +
                        '<span class="sr-snippet">' + snippet + '</span>' +
                    '</a>';
                }).join('');

                var first = results.querySelector('a.search-result');
                if (first) { first.classList.add('is-first'); }
            });
        }

        input.addEventListener('input', render);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                var first = results.querySelector('a.search-result');
                if (first) { event.preventDefault(); location.href = first.getAttribute('href'); }
            }
        });

        modal.addEventListener('mousedown', function (event) {
            if (event.target === modal) { close(); }
        });

        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                if (modal.hidden) { open(); } else { close(); }
                return;
            }
            if (event.key === 'Escape' && !modal.hidden) { close(); }
        });
    }

    function buildSearchButton() {
        var actions = document.querySelector('.topbar-actions');
        if (!actions || document.getElementById('search-open')) { return; }
        var button = document.createElement('button');
        button.id = 'search-open';
        button.type = 'button';
        button.className = 'btn search-open';
        button.innerHTML = '<span aria-hidden="true">🔎</span><span class="search-label">' + t.search + '</span><kbd>Ctrl K</kbd>';
        button.addEventListener('click', function () {
            var modal = document.getElementById('search-modal');
            if (!modal) { return; }
            modal.hidden = false;
            document.body.classList.add('search-open');
            var input = modal.querySelector('#search-modal-input');
            input.value = '';
            modal.querySelector('#search-modal-results').innerHTML = '';
            loadSearchIndex().then(function () { input.focus(); });
        });
        actions.insertBefore(button, actions.firstChild);
    }

    /* ---------- Index page card filter ---------- */
    function wireIndexSearch() {
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
        wireIndexSearch();
        buildSearchButton();
        buildSearchModal();
        applyAnnotations();
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

        /* Deep links: if the page opens with #section but the heading was
           rendered late, scroll to it once the TOC ids are in place. Use an
           instant scroll so the position is final even in a background tab. */
        if (location.hash) {
            var id = decodeURIComponent(location.hash.slice(1));
            var target = document.getElementById(id);
            if (target) {
                try { target.scrollIntoView({ behavior: 'instant', block: 'start' }); }
                catch (e) { target.scrollIntoView(); }
                /* Let the sidebar TOC recompute immediately; some embedded
                   browsers deliver the scroll notification late. */
                window.dispatchEvent(new Event('scroll'));
            }
        }
    });
})();
