import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['test/**/*.test.{ts,tsx}'],
        setupFiles: ['test/setup.ts'],
        // Style tests read the stylesheets as text (`?raw`); Vitest blanks unprocessed CSS.
        css: { include: [/\/src\/styles\/.+\.css/] },
        // Date-time tests assert device offsets; pin the zone so CI (UTC) matches.
        env: { TZ: 'Europe/Berlin' },
        coverage: {
            provider: 'v8',
            include: ['src/**/*.{ts,tsx}'],
            exclude: ['src/**/*.d.ts'],
            reporter: ['text', 'json-summary'],
            thresholds: { lines: 80 },
        },
    },
});
