import { describe, expect, it } from 'vitest';
import { de } from '../../src/i18n/de.ts';
import { en } from '../../src/i18n/en.ts';
import { formatMessage } from '../../src/i18n/I18nProvider.tsx';
import {
    browserLanguage,
    loadPreferences,
    savePreferences,
    toLanguage,
} from '../../src/i18n/preferences.ts';

const placeholders = (text: string): string[] =>
    [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1] ?? '').sort();

describe('message catalogs', () => {
    it('have the same keys in DE and EN', () => {
        expect(Object.keys(de).sort()).toEqual(Object.keys(en).sort());
    });

    it('use the same placeholders and no empty texts', () => {
        for (const key of Object.keys(en) as (keyof typeof en)[]) {
            expect(placeholders(de[key]), key).toEqual(placeholders(en[key]));
            expect(de[key].trim(), key).not.toBe('');
            expect(en[key].trim(), key).not.toBe('');
        }
    });
});

describe('i18n helpers', () => {
    it('interpolates placeholders and keeps unknown ones', () => {
        expect(formatMessage('Hi {name}, {missing}', { name: 'Ana' })).toBe('Hi Ana, {missing}');
    });

    it('maps locales to supported languages', () => {
        expect(toLanguage('de-DE')).toBe('de');
        expect(toLanguage('en_GB')).toBe('en');
        expect(toLanguage('fr')).toBeUndefined();
        expect(toLanguage(null)).toBeUndefined();
        expect(['de', 'en']).toContain(browserLanguage());
    });

    it('persists preferences', () => {
        localStorage.clear();
        expect(loadPreferences()).toEqual({ largeText: false });
        savePreferences({ language: 'de', largeText: true });
        expect(loadPreferences()).toEqual({ language: 'de', largeText: true });
        localStorage.setItem('fieldset.settings', '{"language":"xx"}');
        expect(loadPreferences()).toEqual({ largeText: false });
    });
});
