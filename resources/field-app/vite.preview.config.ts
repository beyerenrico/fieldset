import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * Builder preview: a separate entry outside the /app service-worker scope. It bundles only the
 * renderer (no router, no service worker, no storage) → public/form-preview/preview.html.
 */
export default defineConfig({
    base: '/form-preview/',
    plugins: [react()],
    build: {
        outDir: '../../public/form-preview',
        emptyOutDir: true,
        sourcemap: true,
        rolldownOptions: { input: { preview: 'preview.html' } },
    },
});
