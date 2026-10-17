import type { PluginOption } from 'vite';
import { describe, expect, it } from 'vitest';
import previewConfig from '../vite.preview.config.ts';
import { importGraph, runtimeImports } from './utils/b-imports.ts';

const sources = import.meta.glob<string>('../src/**/*.{ts,tsx}', {
    query: '?raw',
    import: 'default',
    eager: true,
});
const rendererSources = import.meta.glob<string>(
    '../../../packages/{form-schema-react,form-schema-js}/src/**/*.{ts,tsx}',
    { query: '?raw', import: 'default', eager: true },
);

/** Offline machinery that must never run inside the builder preview (§6.1). */
const OFFLINE_DIRS = ['db', 'sync', 'pwa', 'outbox', 'catalog', 'attachments', 'drafts'];
const OFFLINE_PACKAGES = /^(dexie|workbox-|virtual:pwa-register)/;

function pluginNames(plugins: readonly PluginOption[] | undefined): string[] {
    return (plugins ?? []).flatMap((plugin): string[] => {
        if (Array.isArray(plugin)) {
            return pluginNames(plugin);
        }
        return plugin !== null && typeof plugin === 'object' && 'name' in plugin
            ? [plugin.name]
            : [];
    });
}

describe('builder preview build (/form-preview/)', () => {
    it('is a separate build without the PWA plugin and without the public/ PWA assets', () => {
        expect(previewConfig.base).toBe('/form-preview/');
        expect(previewConfig.publicDir).toBe(false);
        expect(previewConfig.build?.outDir).toBe('../../public/form-preview');
        expect(previewConfig.build?.rolldownOptions?.input).toEqual({ preview: 'preview.html' });
        // connect-src 'none' in the sandboxed preview: no fetch()-based modulepreload polyfill.
        expect(previewConfig.build?.modulePreload).toEqual({ polyfill: false });
        const names = pluginNames(previewConfig.plugins);
        expect(names.length).toBeGreaterThan(0);
        expect(names.filter((name) => name.includes('pwa'))).toEqual([]);
    });

    it('never reaches the service worker, Dexie or the sync engine from its entry', () => {
        const graph = importGraph(sources, '../src/preview/preview-main.tsx');
        expect(graph.files.has('../src/preview/PreviewApp.tsx')).toBe(true);
        const offline = [...graph.files].filter((file) =>
            OFFLINE_DIRS.some((dir) => file.startsWith(`../src/lib/${dir}/`)),
        );
        expect(offline).toEqual([]);
        expect([...graph.packages].filter((name) => OFFLINE_PACKAGES.test(name))).toEqual([]);
    });

    it('never touches storage, which throws in the sandbox (opaque origin, ADR 0040)', () => {
        const graph = importGraph(sources, '../src/preview/preview-main.tsx');
        const reached = [
            ...[...graph.files].map((file) => sources[file] ?? ''),
            ...Object.values(rendererSources),
        ];
        expect(reached.length).toBeGreaterThan(1);
        const storage = /\b(localStorage|sessionStorage|indexedDB|document\.cookie|caches\.open)\b/;
        expect(storage.test('window.localStorage.getItem(key)')).toBe(true);
        expect(reached.filter((source) => storage.test(source))).toEqual([]);
    });

    it('bundles a renderer that has no offline dependencies either', () => {
        const imported = Object.values(rendererSources).flatMap(runtimeImports);
        expect(imported.length).toBeGreaterThan(0);
        expect(imported.filter((name) => OFFLINE_PACKAGES.test(name))).toEqual([]);
    });

    it('while the Field App entry does register the service worker (the walker works)', () => {
        const graph = importGraph(sources, '../src/main.tsx');
        expect(graph.files.has('../src/lib/pwa/start.ts')).toBe(true);
        expect(graph.files.has('../src/lib/db/db.ts')).toBe(true);
        expect(graph.packages.has('virtual:pwa-register')).toBe(true);
        expect(graph.packages.has('dexie')).toBe(true);
    });
});
