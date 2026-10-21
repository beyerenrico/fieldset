/**
 * vite-plugin-pwa options of the Field App (ADR 0035, §6.1). Build-time only: imported by
 * vite.config.ts and the config tests, never by the app bundle (no DOM or Node APIs here).
 *
 * The shell trick: Workbox precaches the built index.html under the URL `shell` (= /app/shell),
 * which no static file answers, so the install fetch goes through the Laravel catch-all and the
 * cached response carries the CSP that SpaController sets. Navigations are answered from it.
 */
import type { VitePWAOptions } from 'vite-plugin-pwa';

export const APP_BASE = '/app/';
/** Precache URL of the app shell, relative to the worker (/app/sw.js → /app/shell). */
export const SHELL_URL = 'shell';
export const THEME_COLOR = '#0f172a';
/** Background Sync handler (public/sw-sync.js), pulled into the generated worker. */
export const SW_SYNC_SCRIPT = 'sw-sync.js';

/** Navigations that must reach the network (outside the scope anyway; kept as a second guard). */
export const NAVIGATION_DENYLIST: readonly RegExp[] = [/^\/api\//, /^\/sanctum\//, /^\/auth\//];

/** Workbox precache entry as handed to `manifestTransforms` (workbox-build ManifestTransform). */
export interface PrecacheEntry {
    url: string;
    revision: string | null;
    size: number;
    integrity?: string;
}

export interface PrecacheTransformResult {
    manifest: PrecacheEntry[];
    warnings: string[];
}

/** Precaches index.html only under SHELL_URL (same revision), never as the static file. */
export function shellManifestTransform(entries: readonly PrecacheEntry[]): PrecacheTransformResult {
    const index = entries.find((entry) => entry.url === 'index.html');
    const rest = entries.filter((entry) => entry.url !== 'index.html');
    return index === undefined
        ? { manifest: rest, warnings: ['index.html is missing: the app shell is not precached'] }
        : { manifest: [...rest, { ...index, url: SHELL_URL }], warnings: [] };
}

const ICONS = [
    { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
    { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
    {
        src: 'icons/icon-maskable-512.png',
        sizes: '512x512',
        type: 'image/png',
        purpose: 'maskable',
    },
];

/** `swSyncRevision` busts the HTTP cache of the imported script whenever its content changes. */
export function fieldAppPwaOptions(swSyncRevision: string): Partial<VitePWAOptions> {
    return {
        strategies: 'generateSW',
        registerType: 'prompt',
        injectRegister: false,
        base: APP_BASE,
        scope: APP_BASE,
        filename: 'sw.js',
        manifestFilename: 'manifest.webmanifest',
        includeAssets: ['icons/icon.svg', 'icons/apple-touch-icon-180.png'],
        manifest: {
            id: APP_BASE,
            name: 'Fieldset Field App',
            short_name: 'Field App',
            description: 'Fill in forms on site, also offline.',
            lang: 'de',
            start_url: APP_BASE,
            scope: APP_BASE,
            display: 'standalone',
            background_color: '#ffffff',
            theme_color: THEME_COLOR,
            icons: ICONS,
        },
        workbox: {
            // Hashed JS/CSS/fonts plus index.html (renamed to the shell); icons and the web
            // manifest are added by the plugin (includeAssets, manifest icons).
            globPatterns: ['**/*.{js,css,html,woff,woff2}'],
            globIgnores: ['**/node_modules/**/*', SW_SYNC_SCRIPT],
            manifestTransforms: [shellManifestTransform],
            navigateFallback: SHELL_URL,
            navigateFallbackDenylist: [...NAVIGATION_DENYLIST],
            importScripts: [`${SW_SYNC_SCRIPT}?v=${swSyncRevision}`],
            // API responses are never cached by the worker: the offline store is IndexedDB.
            runtimeCaching: [],
            cleanupOutdatedCaches: true,
        },
    };
}
