import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import pkg from './package.json' with { type: 'json' };

const backend = process.env.FIELD_APP_BACKEND ?? 'http://localhost:8000';

/** Field App: served by Laravel at /app (SPA fallback to public/app/index.html). */
export default defineConfig({
    base: '/app/',
    plugins: [react(), tailwindcss()],
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
