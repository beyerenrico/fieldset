import { describe, expect, it } from 'vitest';
import {
    fieldAppPwaOptions,
    NAVIGATION_DENYLIST,
    SHELL_URL,
    shellManifestTransform,
    type PrecacheEntry,
} from '../../../src/lib/pwa/pwa-options.ts';

/** vite.config.ts as text: importing it would load Tailwind and Workbox into the test. */
const viteConfigSource = Object.values(
    import.meta.glob<string>('../../../vite.config.ts', {
        query: '?raw',
        import: 'default',
        eager: true,
    }),
)[0];

const options = fieldAppPwaOptions('rev123');

describe('service worker build options', () => {
    it('registers a prompted generateSW worker at /app/sw.js with scope /app/', () => {
        expect(options).toMatchObject({
            strategies: 'generateSW',
            registerType: 'prompt',
            injectRegister: false,
            base: '/app/',
            scope: '/app/',
            filename: 'sw.js',
            manifestFilename: 'manifest.webmanifest',
        });
        expect(viteConfigSource).toContain("base: '/app/'");
        expect(viteConfigSource).toContain("outDir: '../../public/app'");
        expect(viteConfigSource).toContain('VitePWA(fieldAppPwaOptions(swSyncRevision))');
    });

    it('describes an installable standalone app with any and maskable icons', () => {
        const manifest = options.manifest;
        expect(manifest).toMatchObject({
            id: '/app/',
            start_url: '/app/',
            scope: '/app/',
            display: 'standalone',
            theme_color: '#0f172a',
        });
        const icons = manifest === false || manifest === undefined ? [] : (manifest.icons ?? []);
        expect(icons.map((icon) => `${icon.sizes ?? ''} ${String(icon.purpose)}`)).toEqual([
            '192x192 any',
            '512x512 any',
            '512x512 maskable',
        ]);
    });

    it('denylists API, Sanctum and auth navigations and caches no API responses', () => {
        const workbox = options.workbox ?? {};
        expect(workbox.navigateFallback).toBe(SHELL_URL);
        const denied = (path: string): boolean =>
            (workbox.navigateFallbackDenylist ?? []).some((pattern) => pattern.test(path));
        expect(denied('/api/v1/forms')).toBe(true);
        expect(denied('/sanctum/csrf-cookie')).toBe(true);
        expect(denied('/auth/login')).toBe(true);
        expect(denied('/app/forms')).toBe(false);
        expect(workbox.navigateFallbackDenylist).toEqual(NAVIGATION_DENYLIST);
        expect(workbox.runtimeCaching).toEqual([]);
    });

    it('precaches assets but imports sw-sync.js with a content revision instead', () => {
        const workbox = options.workbox ?? {};
        expect(workbox.globPatterns).toEqual(['**/*.{js,css,html,woff,woff2}']);
        expect(workbox.globIgnores).toContain('sw-sync.js');
        expect(workbox.importScripts).toEqual(['sw-sync.js?v=rev123']);
        expect(workbox.manifestTransforms).toEqual([shellManifestTransform]);
        expect(options.includeAssets).toEqual(['icons/icon.svg', 'icons/apple-touch-icon-180.png']);
    });
});

describe('app shell precache entry', () => {
    const entry = (url: string, revision: string | null = 'r'): PrecacheEntry => ({
        url,
        revision,
        size: 1,
    });

    it('precaches index.html only as /app/shell (served by Laravel with the CSP)', () => {
        const result = shellManifestTransform([
            entry('assets/index-abc.js', null),
            entry('index.html', 'html-rev'),
        ]);
        expect(result).toEqual({
            manifest: [entry('assets/index-abc.js', null), entry('shell', 'html-rev')],
            warnings: [],
        });
    });

    it('warns when the build has no index.html', () => {
        const result = shellManifestTransform([entry('assets/a.js')]);
        expect(result.manifest).toEqual([entry('assets/a.js')]);
        expect(result.warnings).toHaveLength(1);
    });
});
