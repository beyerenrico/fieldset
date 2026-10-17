import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';
import pkg from './package.json' with { type: 'json' };
import { fieldAppPwaOptions, SW_SYNC_SCRIPT } from './src/lib/pwa/pwa-options';

const backend = process.env.FIELD_APP_BACKEND ?? 'http://localhost:8000';

/** Content hash of public/sw-sync.js: a changed handler changes the worker and busts caches. */
const swSyncRevision = createHash('sha256')
    .update(readFileSync(new URL(`./public/${SW_SYNC_SCRIPT}`, import.meta.url)))
    .digest('hex')
    .slice(0, 12);

/**
 * Field App: served by Laravel at /app (SPA fallback to public/app/index.html). The service worker
 * (vite-plugin-pwa, ADR 0035) is emitted as public/app/sw.js with scope /app/.
 */
export default defineConfig({
    base: '/app/',
    plugins: [react(), tailwindcss(), VitePWA(fieldAppPwaOptions(swSyncRevision))],
    define: { __APP_VERSION__: JSON.stringify(pkg.version) },
    build: {
        outDir: '../../public/app',
        emptyOutDir: true,
        sourcemap: true,
    },
    server: {
        proxy: {
            '/api': { target: backend, changeOrigin: false },
            '/sanctum': { target: backend, changeOrigin: false },
        },
    },
});
