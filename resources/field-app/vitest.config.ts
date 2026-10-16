import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    define: { __APP_VERSION__: JSON.stringify('0.0.0-test') },
    test: {
        environment: 'jsdom',
        environmentOptions: { jsdom: { url: 'http://localhost:3000/app/' } },
        include: ['test/**/*.test.{ts,tsx}'],
        setupFiles: ['test/setup.ts'],
        // Style tests read the stylesheet as text (`?raw`); Vitest blanks unprocessed CSS.
        css: { include: [/\/src\/styles\/.+\.css/] },
        coverage: {
            provider: 'v8',
            include: ['src/**/*.{ts,tsx}'],
            exclude: ['src/**/*.d.ts', 'src/main.tsx', 'src/preview/preview-main.tsx'],
            reporter: ['text', 'json-summary'],
            thresholds: { lines: 80 },
        },
    },
});
