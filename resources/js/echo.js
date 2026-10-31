import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

function readInertiaProps() {
    try {
        const el = document.getElementById('app');
        if (el?.dataset?.page) {
            const page = JSON.parse(el.dataset.page);
            window.__INERTIA_PAGE_PROPS__ = page.props ?? {};
        }
    } catch {}
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function initEcho() {
    if (window.Echo) return; // already initialised

    readInertiaProps();

    const props = window.__INERTIA_PAGE_PROPS__ ?? {};
    const broadcastConfig = props.broadcasting ?? {};
    const pusherLegacy = props.pusher ?? window.__pusherConfig__ ?? {};

    // Determine broadcaster driver:
    // 1. Explicit driver from backend Inertia prop
    // 2. Fallback to Vite env variables
    let driver = broadcastConfig.driver;
    if (!driver || driver === 'log' || driver === 'null') {
        if (import.meta.env.VITE_REVERB_APP_KEY) {
            driver = 'reverb';
        } else if (pusherLegacy.key || import.meta.env.VITE_PUSHER_APP_KEY) {
            driver = 'pusher';
        } else {
            // Realtime is deliberately off (log/null)
            return;
        }
    }

    const csrf = getCsrfToken();
    if (!csrf) {
        // eslint-disable-next-line no-console
        console.warn('[echo] CSRF meta tag missing — broadcasting/auth will likely fail.');
    }

    const authHeaders = {
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        },
        authTransport: 'ajax',
    };

    if (driver === 'reverb') {
        const key = broadcastConfig.key || import.meta.env.VITE_REVERB_APP_KEY || '';
        const host = broadcastConfig.host || import.meta.env.VITE_REVERB_HOST || 'localhost';
        const port = Number(broadcastConfig.port || import.meta.env.VITE_REVERB_PORT || 8080);
        const scheme = broadcastConfig.scheme || import.meta.env.VITE_REVERB_SCHEME || 'http';
        const enabled = broadcastConfig.enabled !== undefined ? broadcastConfig.enabled : !!key;

        if (!key || !enabled) {
            // eslint-disable-next-line no-console
            console.warn('[echo] Reverb disabled or key missing — real-time disabled.', { enabled, hasKey: !!key });
            return;
        }

        window.Echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            ...authHeaders,
        });

        window.Echo.connector?.pusher?.connection?.bind('connected', () => {
            // eslint-disable-next-line no-console
            console.info('[echo] Connected to Reverb realtime server.');
        });

        window.Echo.connector?.pusher?.connection?.bind('error', (err) => {
            // eslint-disable-next-line no-console
            console.warn('[echo] Reverb connection error', err);
        });

        return;
    }

    if (driver === 'pusher') {
        const key = broadcastConfig.key || pusherLegacy.key || import.meta.env.VITE_PUSHER_APP_KEY || '';
        const cluster = broadcastConfig.cluster || pusherLegacy.cluster || import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';
        const enabled = broadcastConfig.enabled !== undefined ? broadcastConfig.enabled : (pusherLegacy.enabled !== undefined ? pusherLegacy.enabled : !!key);

        if (!key || !enabled) {
            // eslint-disable-next-line no-console
            console.warn('[echo] Pusher disabled or key missing — real-time disabled.', { enabled, hasKey: !!key });
            return;
        }

        window.Echo = new Echo({
            broadcaster: 'pusher',
            key,
            cluster,
            forceTLS: true,
            disableStats: true,
            enabledTransports: ['ws', 'wss'],
            ...authHeaders,
        });

        window.Echo.connector?.pusher?.connection?.bind('connected', () => {
            // eslint-disable-next-line no-console
            console.info('[echo] Connected to Pusher realtime server.');
        });

        window.Echo.connector?.pusher?.connection?.bind('error', (err) => {
            // eslint-disable-next-line no-console
            console.warn('[echo] Pusher connection error', err);
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEcho, { once: true });
} else {
    initEcho();
}
