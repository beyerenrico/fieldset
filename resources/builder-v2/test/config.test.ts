import { describe, expect, it } from 'vitest';
import { ConfigError, parseConfig, readConfig } from '../src/config.ts';
import { replaceParams, toStrings, Translator } from '../src/i18n.ts';
import { contractConfig } from './support/fixtures.ts';

describe('config', () => {
    it('reads the embedded contract config', () => {
        document.body.innerHTML =
            '<div><script type="application/json" data-builder-config></script></div>';
        document.querySelector('script')!.textContent = JSON.stringify(contractConfig());
        expect(readConfig(document.body)).toEqual(contractConfig());
    });

    it('refuses configs without a definition and tolerates missing optional parts', () => {
        expect(() => parseConfig({ checksum: 'x' })).toThrow(ConfigError);
        document.body.innerHTML =
            '<script type="application/json" data-builder-config>{oops</script>';
        expect(() => readConfig(document.body)).toThrow(ConfigError);
        const config = parseConfig({
            definition: { pages: [] },
            checksum: 'x',
            lockedIds: ['a', 3],
            maxContainerDepth: 0,
            palette: [{ items: [{ type: 'text', node: {} }, { type: 1 }] }, 5],
        });
        expect(config.lockedIds).toEqual(['a']);
        expect(config.maxContainerDepth).toBe(3);
        expect(config.palette).toEqual([
            {
                group: 'group',
                label: '',
                items: [{ type: 'text', label: 'text', icon: '', node: {} }],
            },
        ]);
        expect(config.urls).toEqual({ preview: '/form-preview/', classic: '' });
    });
});

describe('i18n', () => {
    it('looks up nested keys, replaces placeholders and falls back to the key', () => {
        const tr = new Translator(
            toStrings({
                ui: { a: { b: 'Hi :name, :names' } },
                lint: { x: 'X' },
                types: { text: 'Text' },
            }),
        );
        expect(tr.t('a.b', { name: 'Ada', names: 'all' })).toBe('Hi Ada, all');
        expect(tr.t('missing.key')).toBe('missing.key');
        expect(tr.lintLabel('x')).toBe('X');
        expect(tr.lintLabel('y')).toBe('y');
        expect(tr.typeLabel('text')).toBe('Text');
        expect(replaceParams(':count of :counter', { count: 1, counter: 2 })).toBe('1 of 2');
        expect(toStrings(null)).toEqual({ ui: {}, lint: {}, types: {} });
    });
});
