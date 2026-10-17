import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * Builder preview: a separate entry outside the /app service-worker scope. It bundles only the
 * renderer (no router, no service worker, no storage) → public/form-preview/preview.html.
 * `publicDir: false`: the Field App's public/ (PWA icons, sw-sync.js) must not be copied here.
 */
export default defineConfig({
    base: '/form-preview/',
    publicDir: false,
    plugins: [react()],
    build: {
        outDir: '../../public/form-preview',
        emptyOutDir: true,
        sourcemap: true,
        rolldownOptions: { input: { preview: 'preview.html' } },
    },
});
