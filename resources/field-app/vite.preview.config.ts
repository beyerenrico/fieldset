import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * Builder preview: a separate entry outside the /app service-worker scope. It bundles only the
 * renderer (no router, no service worker, no storage) → public/form-preview/preview.html.
 * `publicDir: false`: the Field App's public/ (PWA icons, sw-sync.js) must not be copied here.
 *
 * The preview runs sandboxed in an opaque origin (ADR 0040): its assets are CORS requests with
 * `Origin: null` (the web server adds Access-Control-Allow-Origin for /form-preview/assets/), and its
 * CSP has `connect-src 'none'`, so the modulepreload polyfill (a fetch() fallback for browsers
 * without native modulepreload) is left out.
 */
export default defineConfig({
    base: '/form-preview/',
    publicDir: false,
    plugins: [react()],
    build: {
        outDir: '../../public/form-preview',
        emptyOutDir: true,
        sourcemap: true,
        modulePreload: { polyfill: false },
        rolldownOptions: { input: { preview: 'preview.html' } },
    },
});
