/**
 * AYDRA docs screenshot capture — Chrome DevTools Protocol, no dependencies.
 *
 * Usage:
 *   node docs-capture.mjs --lang en                  all screens, light theme (WebP)
 *   node docs-capture.mjs --lang ar --only inbox     one screen
 *   node docs-capture.mjs --lang en --mode dark      dark-theme variants
 *   node docs-capture.mjs --lang en --mode mobile    mobile variants
 *   node docs-capture.mjs --lang en --probe          dump candidate annotation targets
 *
 * Screenshots are written to public/docs/assets/img/<lang>/<slug>[-dark|-mobile].webp.
 * Annotation coordinates are written to public/docs/assets/data/annotations.json.
 *
 * The UI language is a stored user preference, so set it first with
 * `php doc/scripts/set-locale.php <email> <code>` before running. Chrome must be
 * installed and the app served by Herd at https://crm.test.
 */
import { spawn } from 'node:child_process';
import { mkdirSync, existsSync, rmSync, writeFileSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as sleep } from 'node:timers/promises';

const HERE = dirname(fileURLToPath(import.meta.url));
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'https://crm.test';
const PORT = 9300 + Math.floor(Math.random() * 300);
const ROOT = 'C:\\Users\\Mazen\\Herd\\crm\\public\\docs\\assets';
const PROFILE = 'C:\\Users\\Mazen\\AppData\\Local\\Temp\\opencode\\aydra-chrome-profile';
const EMAIL = 'business@aydra.demo';
const PASSWORD = 'business@aydra.demo';

const args = process.argv.slice(2);
const lang = (args[args.indexOf('--lang') + 1] || 'en').toLowerCase();
const only = args.includes('--only') ? args[args.indexOf('--only') + 1] : null;
const mode = args.includes('--mode') ? args[args.indexOf('--mode') + 1] : 'light';
const probe = args.includes('--probe');

/** route -> file slug, grouped to mirror the guides. */
const SHOTS = [
    // 01 Getting started
    { route: '/app/dashboard', slug: 'dashboard', hero: true, dark: true },
    { route: '/app/onboarding', slug: 'onboarding' },
    { route: '/app/pricing', slug: 'pricing' },
    // 02 Setup & integrations
    { route: '/app/settings', slug: 'settings', hero: true, mobile: true },
    { route: '/app/whatsapp/setup', slug: 'whatsapp-setup', hero: true, dark: true },
    { route: '/app/ai/providers', slug: 'ai-providers' },
    { route: '/app/broadcasts/email-server', slug: 'email-server' },
    // 03 Inbox
    { route: '/app/inbox', slug: 'inbox', hero: true, mobile: true },
    { route: '/app/inbox/labels', slug: 'inbox-labels' },
    { route: '/app/inbox/canned-replies', slug: 'canned-replies' },
    // 04 Contacts
    { route: '/app/contacts', slug: 'contacts', hero: true },
    { route: '/app/contacts/bulk-import', slug: 'contacts-import' },
    { route: '/app/segments', slug: 'segments' },
    { route: '/app/custom-fields', slug: 'custom-fields' },
    // 05 Leads
    { route: '/app/leads', slug: 'leads', hero: true, mobile: true },
    { route: '/app/leads/pipeline', slug: 'pipeline', dark: true, mobile: true },
    { route: '/app/leads/companies', slug: 'companies' },
    { route: '/app/tasks', slug: 'tasks' },
    // 06 Sales
    { route: '/app/sales/products', slug: 'products' },
    { route: '/app/sales/quotes', slug: 'quotes', hero: true, dark: true },
    { route: '/app/sales/invoices', slug: 'invoices' },
    { route: '/app/sales/sequences', slug: 'sequences' },
    { route: '/app/sales/calls', slug: 'calls' },
    { route: '/app/sales/ai-sdr', slug: 'ai-sdr' },
    // 07 Marketing & social
    { route: '/app/broadcasts/campaigns', slug: 'campaigns', hero: true },
    { route: '/app/whatsapp/templates', slug: 'wa-templates' },
    { route: '/app/whatsapp/auto-replies', slug: 'auto-replies' },
    { route: '/app/social/composer', slug: 'social-composer' },
    { route: '/app/social/calendar', slug: 'social-calendar' },
    // 08 Automation & AI
    { route: '/app/automations', slug: 'automations', hero: true, dark: true },
    { route: '/app/ai/knowledge-bases', slug: 'knowledge-bases' },
    { route: '/app/ai/chatbots', slug: 'chatbots' },
    // 09 Reports
    { route: '/app/reports/sales', slug: 'reports-sales', hero: true, dark: true },
    { route: '/app/reports/inbox', slug: 'reports-inbox' },
    { route: '/app/reports/automations', slug: 'reports-automations' },
    { route: '/app/reports/ai', slug: 'reports-ai' },
    { route: '/app/reports/social', slug: 'reports-social' },
    // 10 Admin
    { route: '/app/team', slug: 'team', hero: true },
    { route: '/app/billing', slug: 'billing' },
    { route: '/app/api-tokens', slug: 'api-tokens' },
    { route: '/app/webhooks', slug: 'webhooks' },
    { route: '/app/audit-log', slug: 'audit-log' },
];

/**
 * Annotation targets, keyed by slug then language. `selector` must resolve to a
 * single visible element on the captured page. `label` is the badge tooltip
 * shown in the docs. Any slug without an entry simply gets no badges.
 */
const ANNOTATION_SPECS = {
    dashboard: {
        en: [
            { selector: 'main h1', label: 'Your workspace at a glance' },
            { selector: 'a[href$="/app/inbox"]', label: 'Inbox: all conversations' },
            { selector: 'a[href$="/app/leads"]', label: 'Sales: leads and pipeline' },
        ],
        ar: [
            { selector: 'main h1', label: 'نظرة سريعة على مساحة العمل' },
            { selector: 'a[href$="/app/inbox"]', label: 'صندوق الوارد: كل المحادثات' },
            { selector: 'a[href$="/app/leads"]', label: 'المبيعات: الليدز وخط الأنابيب' },
        ],
    },
    inbox: {
        en: [
            { selector: 'input[placeholder*="Search conversations"], input[placeholder*="البحث في المحادثات"]', label: 'Filter the conversation list' },
            { selector: 'a[href*="/app/inbox/conversations/"]', label: 'Open a conversation' },
        ],
        ar: [
            { selector: 'input[placeholder*="Search conversations"], input[placeholder*="البحث في المحادثات"]', label: 'صفِّ قائمة المحادثات' },
            { selector: 'a[href*="/app/inbox/conversations/"]', label: 'افتح محادثة من القائمة' },
        ],
    },
    contacts: {
        en: [
            { selector: 'main h1', label: 'All contacts' },
            { selector: 'input[placeholder*="Search by name"]', label: 'Search or filter' },
        ],
        ar: [
            { selector: 'main h1', label: 'كل جهات الاتصال' },
            { selector: 'a[href$="/app/contacts/bulk-import"]', label: 'استيراد جهات الاتصال' },
        ],
    },
    leads: {
        en: [
            { selector: 'main h1', label: 'Lead scraper results' },
            { selector: 'input[placeholder*="Search leads"]', label: 'Search and filter leads' },
        ],
        ar: [
            { selector: 'main h1', label: 'نتائج جلب الليدز' },
            { selector: 'a[href$="/app/leads/pipeline"]', label: 'افتح خط الأنابيب' },
        ],
    },
    quotes: {
        en: [{ selector: 'main h1', label: 'Quotes and their status' }],
        ar: [{ selector: 'main h1', label: 'العروض وحالتها' }],
    },
    campaigns: {
        en: [
            { selector: 'main h1', label: 'Campaign performance' },
            { selector: 'main table', label: 'Delivery and reply rates' },
        ],
        ar: [
            { selector: 'main h1', label: 'أداء الحملات' },
            { selector: 'main table', label: 'معدلات التسليم والرد' },
        ],
    },
    automations: {
        en: [
            { selector: 'main h1', label: 'Your automation scenarios' },
            { selector: 'main table, main [class*="card"]', label: 'Status and run count' },
        ],
        ar: [
            { selector: 'main h1', label: 'سيناريوهات الأتمتة' },
            { selector: 'main table, main [class*="card"]', label: 'الحالة وعدد التشغيلات' },
        ],
    },
    'reports-sales': {
        en: [{ selector: 'main h1', label: 'Sales KPIs and charts' }],
        ar: [{ selector: 'main h1', label: 'مؤشرات المبيعات والرسوم' }],
    },
    team: {
        en: [{ selector: 'main h1', label: 'Team members and roles' }],
        ar: [{ selector: 'main h1', label: 'أعضاء الفريق والأدوار' }],
    },
    settings: {
        en: [{ selector: 'main h1', label: 'Workspace settings' }],
        ar: [{ selector: 'main h1', label: 'إعدادات مساحة العمل' }],
    },
    'whatsapp-setup': {
        en: [
            { selector: 'main h2', label: 'Channel connections at a glance' },
            { selector: 'main button', label: 'Connect WhatsApp, Messenger or Instagram' },
        ],
        ar: [
            { selector: 'main h2', label: 'حالة قنوات الاتصال' },
            { selector: 'main button', label: 'اربط واتساب أو ماسنجر أو إنستجرام' },
        ],
    },
};

/* Hide the local-dev widgets Herd injects so the shots show the product only. */
const CLEANUP_CSS = `
#herd-ai-root, #herd-ai-host, #herd-studio-root, [id^="herd-ai"], [id^="herd-studio"],
iframe[src*="herd-studio"], iframe[src*="herd.ai"], .herd-studio-bubble { display: none !important; }
`;

class CDP {
    constructor(ws) {
        this.ws = ws; this.id = 0; this.pending = new Map(); this.listeners = new Map();
        ws.addEventListener('message', (ev) => {
            const msg = JSON.parse(ev.data);
            if (msg.id && this.pending.has(msg.id)) {
                const { resolve, reject } = this.pending.get(msg.id);
                this.pending.delete(msg.id);
                if (msg.error) { reject(new Error(msg.error.message)); } else { resolve(msg.result); }
            } else if (msg.method) {
                const key = (msg.sessionId || '') + ':' + msg.method;
                (this.listeners.get(key) || []).forEach((cb) => cb(msg.params));
            }
        });
    }
    send(method, params = {}, sessionId) {
        const id = ++this.id;
        const payload = { id, method, params };
        if (sessionId) { payload.sessionId = sessionId; }
        return new Promise((resolve, reject) => { this.pending.set(id, { resolve, reject }); this.ws.send(JSON.stringify(payload)); });
    }
    on(method, cb, sessionId) {
        const key = (sessionId || '') + ':' + method;
        if (!this.listeners.has(key)) { this.listeners.set(key, []); }
        this.listeners.get(key).push(cb);
    }
    once(method, sessionId, timeoutMs = 20000) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('timeout waiting for ' + method)), timeoutMs);
            const key = (sessionId || '') + ':' + method;
            const handler = (params) => {
                clearTimeout(timer);
                this.listeners.set(key, (this.listeners.get(key) || []).filter((cb) => cb !== handler));
                resolve(params);
            };
            if (!this.listeners.has(key)) { this.listeners.set(key, []); }
            this.listeners.get(key).push(handler);
        });
    }
}

async function fetchJson(url, tries = 40) {
    for (let i = 0; i < tries; i++) {
        try { const res = await fetch(url); if (res.ok) { return await res.json(); } } catch (e) { /* not up yet */ }
        await sleep(250);
    }
    throw new Error('could not reach ' + url);
}

async function main() {
    if (!existsSync(CHROME)) { throw new Error('Chrome not found at ' + CHROME); }
    rmSync(PROFILE, { recursive: true, force: true });

    const chrome = spawn(CHROME, [
        '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
        '--no-default-browser-check', '--ignore-certificate-errors', '--disable-extensions',
        '--window-size=1600,1000', '--remote-debugging-port=' + PORT,
        '--user-data-dir=' + PROFILE, 'about:blank',
    ], { stdio: 'ignore' });

    const version = await fetchJson(`http://127.0.0.1:${PORT}/json/version`);
    const ws = new WebSocket(version.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        ws.addEventListener('open', resolve, { once: true });
        ws.addEventListener('error', reject, { once: true });
    });
    const cdp = new CDP(ws);

    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

    await cdp.send('Page.enable', {}, sessionId);
    await cdp.send('Runtime.enable', {}, sessionId);
    await cdp.send('Network.enable', {}, sessionId);
    await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true }, sessionId).catch(() => {});

    const device = async (kind) => {
        if (kind === 'mobile') {
            await cdp.send('Emulation.setDeviceMetricsOverride', {
                width: 390, height: 844, deviceScaleFactor: 2, mobile: true,
                screenWidth: 390, screenHeight: 844,
            }, sessionId);
            await cdp.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }, sessionId).catch(() => {});
        } else {
            await cdp.send('Emulation.setDeviceMetricsOverride', {
                width: 1600, height: 1000, deviceScaleFactor: 1, mobile: false,
            }, sessionId);
        }
    };
    await device('desktop');

    /* Runs before any page script on every new document: drop the cookie banner
       and hide Herd's local-dev widgets. */
    await cdp.send('Page.addScriptToEvaluateOnNewDocument', {
        source: `try { localStorage.setItem('cookie_consent', 'accepted'); } catch (e) {}
                 (function () {
                     var add = function () {
                         if (!document.head) { return; }
                         var s = document.createElement('style');
                         s.textContent = ${JSON.stringify(CLEANUP_CSS)};
                         document.head.appendChild(s);
                     };
                     if (document.head) { add(); } else { document.addEventListener('DOMContentLoaded', add); }
                 })();`,
    }, sessionId);

    const evalJs = async (expression) => {
        const r = await cdp.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId);
        if (r.exceptionDetails) { throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text)); }
        return r.result.value;
    };

    const goto = async (url) => {
        const loaded = cdp.once('Page.loadEventFired', sessionId, 30000).catch(() => null);
        await cdp.send('Page.navigate', { url }, sessionId);
        await loaded;
    };

    const waitForContent = async (minChars = 150, timeoutMs = 20000) => {
        const start = Date.now();
        while (Date.now() - start < timeoutMs) {
            const state = await evalJs('({ len: (document.body && document.body.innerText || "").length, ready: document.readyState })');
            if (state && state.ready === 'complete' && state.len >= minChars) { return true; }
            await sleep(400);
        }
        return false;
    };

    /* Some Inertia navigations paint late; retry instead of shipping a blank
       screenshot when the first attempt lands mid-transition. */
    const settlePage = async (url) => {
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt > 0) {
                await goto('about:blank').catch(() => {});
                await sleep(600);
            }
            await goto(url);
            await waitForContent();
            await sleep(1500);
            const state = await evalJs(`(() => {
                const main = document.querySelector('main');
                const text = (document.body && document.body.innerText || '').trim();
                return { hasMain: !!main, mainLen: main ? main.innerText.trim().length : 0, textLen: text.length };
            })()`);
            if (state && state.hasMain && state.mainLen > 40) { return state; }
        }
        return null;
    };

    /* ---------- Login ---------- */
    console.log('→ login');
    let loggedIn = false;
    for (let attempt = 1; attempt <= 4 && !loggedIn; attempt++) {
        if (attempt > 1) {
            await cdp.send('Network.clearBrowserCookies', {}, sessionId).catch(() => {});
        }
        await goto(BASE + '/login');
        let ready = false;
        for (let i = 0; i < 40; i++) {
            ready = await evalJs('!!document.querySelector("#email") && !!document.querySelector("#password")');
            if (ready) { break; }
            await sleep(500);
        }
        if (!ready) {
            const state = await evalJs('({ url: location.href, len: document.body ? document.body.innerText.length : -1, head: document.title })');
            console.log(`  attempt ${attempt}: login form missing`, JSON.stringify(state));
            continue;
        }
        const loginResult = await evalJs(`(() => {
            const setVal = (el, val) => {
                const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
                Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, val);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            };
            const email = document.querySelector('#email');
            const password = document.querySelector('#password');
            if (!email || !password) { return { ok: false }; }
            setVal(email, ${JSON.stringify(EMAIL)});
            setVal(password, ${JSON.stringify(PASSWORD)});
            email.closest('form').querySelector('button[type=submit]').click();
            return { ok: true };
        })()`);
        if (!loginResult?.ok) { continue; }
        for (let i = 0; i < 30; i++) {
            if ((await evalJs('location.pathname')) !== '/login') { break; }
            await sleep(500);
        }
        loggedIn = (await evalJs('location.pathname')) !== '/login';
        console.log(`  attempt ${attempt}: path =`, await evalJs('location.pathname'));
    }
    if (!loggedIn) { throw new Error('login failed after retries'); }

    /* Verify the UI language actually matches the requested one. */
    const uiLang = await evalJs('document.documentElement.lang || document.documentElement.dir');
    console.log('  document.lang:', JSON.stringify(uiLang), '(expected ' + lang + ')');

    const setTheme = async (theme) => {
        await evalJs(`try { localStorage.setItem('theme', ${JSON.stringify(theme)}); } catch (e) {}
            document.documentElement.classList.toggle('dark', ${JSON.stringify(theme)} === 'dark');`);
    };

    /* ---------- Probe ---------- */
    if (probe) {
        const list = only ? SHOTS.filter((s) => s.slug === only) : SHOTS.filter((s) => s.hero);
        const out = {};
        for (const shot of list) {
            await device('desktop');
            await goto(BASE + shot.route);
            await waitForContent();
            await sleep(1200);
            out[shot.slug] = await evalJs(`(() => {
                const seen = [];
                const nodes = document.querySelectorAll('main a, main button, main h1, main h2, main h3, main input, main textarea, main select, main [role="button"], aside nav a');
                for (const el of nodes) {
                    const r = el.getBoundingClientRect();
                    if (r.width < 6 || r.height < 6) { continue; }
                    if (r.top < 0 || r.left < 0 || r.top > innerHeight || r.left > innerWidth) { continue; }
                    const text = (el.innerText || el.value || el.getAttribute('aria-label') || el.getAttribute('placeholder') || '').trim().replace(/\\s+/g, ' ').slice(0, 60);
                    if (!text) { continue; }
                    seen.push({ tag: el.tagName.toLowerCase(), text, x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) });
                }
                return { title: document.title, h1: (document.querySelector('main h1') || {}).innerText || '', nodes: seen.slice(0, 80) };
            })()`);
            console.log('  probed ' + shot.slug + ' (' + out[shot.slug].nodes.length + ' nodes)');
        }
        /* Probe output is a working file for tuning the annotation selectors, so
           it stays next to this script instead of inside public/. */
        const probeFile = join(HERE, 'probe-' + lang + '.json');
        writeFileSync(probeFile, JSON.stringify(out, null, 2));
        console.log('→ probe written to ' + probeFile);
        ws.close();
        chrome.kill();
        return;
    }

    /* ---------- Capture ---------- */
    const outDir = ROOT + '\\img\\' + lang;
    mkdirSync(outDir, { recursive: true });
    const annotationsFile = ROOT + '\\data\\annotations.json';
    let annotations = {};
    if (existsSync(annotationsFile)) {
        try { annotations = JSON.parse(readFileSync(annotationsFile, 'utf8')); } catch (e) { annotations = {}; }
    }
    annotations[lang] = annotations[lang] || {};

    const capture = async (slug, suffix, format, quality) => {
        const params = { format, captureBeyondViewport: false };
        if (format !== 'png') { params.quality = quality; }
        const shotData = await cdp.send('Page.captureScreenshot', params, sessionId);
        const buf = Buffer.from(shotData.data, 'base64');
        const name = slug + (suffix ? '-' + suffix : '') + '.' + format;
        writeFileSync(`${outDir}\\${name}`, buf);
        return buf.length;
    };

    const list = only ? SHOTS.filter((s) => s.slug === only) : SHOTS;
    const results = [];

    /* Light pass (and dark pass for flagged heroes). */
    if (mode === 'light' || mode === 'all' || mode === 'dark') {
        for (const shot of list) {
            const wantsDark = (mode === 'dark' || mode === 'all') && shot.dark;
            if (mode === 'dark' && !wantsDark) { continue; }
            try {
                await device('desktop');
                await setTheme('light');
                const settled = await settlePage(BASE + shot.route);
                if (!settled) {
                    results.push({ slug: shot.slug, kind: 'light', ok: false, error: 'page stayed blank' });
                    console.log(`  ✗ ${shot.slug}: page stayed blank`);
                    continue;
                }

                if (mode !== 'dark') {
                    const bytes = await capture(shot.slug, '', 'webp', 82);
                    results.push({ slug: shot.slug, kind: 'light', bytes, ok: true });
                    console.log(`  ✓ ${shot.slug}.webp (${Math.round(bytes / 1024)} KB)`);
                }

                if (wantsDark) {
                    await setTheme('dark');
                    await sleep(900);
                    const bytes = await capture(shot.slug, 'dark', 'webp', 82);
                    results.push({ slug: shot.slug, kind: 'dark', bytes, ok: true });
                    console.log(`  ✓ ${shot.slug}-dark.webp (${Math.round(bytes / 1024)} KB)`);
                }

                /* Annotation coordinates come from the light screenshot geometry. */
                if (shot.hero && ANNOTATION_SPECS[shot.slug]) {
                    await setTheme('light');
                    await sleep(600);
                    const spec = ANNOTATION_SPECS[shot.slug][lang] || [];
                    const points = [];
                    for (const item of spec) {
                        const rect = await evalJs(`(() => {
                            let el = null;
                            try { el = document.querySelector(${JSON.stringify(item.selector)}); } catch (e) { return null; }
                            if (!el) { return null; }
                            const r = el.getBoundingClientRect();
                            if (r.width < 4 || r.height < 4) { return null; }
                            return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width, h: r.height };
                        })()`);
                        if (!rect) { continue; }
                        points.push({
                            x: +(rect.x / 1600 * 100).toFixed(2),
                            y: +(rect.y / 1000 * 100).toFixed(2),
                            label: item.label,
                        });
                    }
                    if (points.length) {
                        annotations[lang][shot.slug] = points;
                        console.log(`    ↳ ${points.length} annotation point(s)`);
                    }
                }
            } catch (e) {
                results.push({ slug: shot.slug, kind: 'light', ok: false, error: String(e.message || e) });
                console.log(`  ✗ ${shot.slug}: ${e.message || e}`);
            }
        }
    }

    /* Mobile pass for the curated screens. */
    if (mode === 'mobile') {
        for (const shot of list.filter((s) => s.mobile)) {
            try {
                await device('mobile');
                await setTheme('light');
                const settled = await settlePage(BASE + shot.route);
                if (!settled) {
                    results.push({ slug: shot.slug, kind: 'mobile', ok: false, error: 'page stayed blank' });
                    console.log(`  ✗ ${shot.slug}-mobile: page stayed blank`);
                    continue;
                }
                const bytes = await capture(shot.slug, 'mobile', 'webp', 82);
                results.push({ slug: shot.slug, kind: 'mobile', bytes, ok: true });
                console.log(`  ✓ ${shot.slug}-mobile.webp (${Math.round(bytes / 1024)} KB)`);
            } catch (e) {
                results.push({ slug: shot.slug, kind: 'mobile', ok: false, error: String(e.message || e) });
                console.log(`  ✗ ${shot.slug}-mobile: ${e.message || e}`);
            }
        }
    }

    if (mode !== 'mobile') {
        mkdirSync(ROOT + '\\data', { recursive: true });
        writeFileSync(annotationsFile, JSON.stringify(annotations, null, 2));
    }

    ws.close();
    chrome.kill();
    const failed = results.filter((r) => !r.ok);
    console.log(`\nDone: ${results.length - failed.length}/${results.length} captured → ${outDir}`);
    if (failed.length) { console.log('Failures:', JSON.stringify(failed, null, 2)); }
}

main().catch((e) => { console.error('FATAL', e); process.exit(1); });
