import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

/**
 * Keep heavy, rarely-shared libraries in their own chunks so they are only
 * downloaded by the pages that need them, and stay cached between deploys.
 *
 * Returning `undefined` for everything else lets Rollup keep its default
 * page-level splitting instead of forcing every dependency into one bundle.
 */
function manualChunks(id) {
    if (!id.includes('node_modules')) {
        return;
    }

    const path = id.replace(/\\/g, '/');

    if (path.includes('/handsontable/')) {
        return 'handsontable';
    }

    if (path.includes('/exceljs/')) {
        return 'exceljs';
    }

    if (path.includes('/recharts/') || path.includes('/d3-')) {
        return 'charts';
    }

    if (path.includes('/@xyflow/')) {
        return 'flow';
    }

    if (path.includes('/@inertiajs/')) {
        return 'inertia';
    }

    if (path.includes('/i18next/') || path.includes('/react-i18next/')) {
        return 'i18n';
    }

    if (path.includes('/react-dom/') || path.includes('/react/') || path.includes('/scheduler/')) {
        return 'react';
    }
}

export default defineConfig({
    server: {
        host: '127.0.0.1', // Avoid IPv6 [::1] so CSP script-src matches without parsing issues
    },
    build: {
        // The only chunks above the default 500 kB are the isolated, lazily
        // loaded vendor bundles for the bulk-import page (handsontable ~1.3 MB,
        // exceljs ~940 kB). They are single third-party libraries that cannot
        // be meaningfully split, so warn only above their size.
        chunkSizeWarningLimit: 1500,
        rollupOptions: {
            output: {
                manualChunks,
            },
        },
    },
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
    ],
});
