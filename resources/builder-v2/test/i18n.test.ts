import { describe, expect, it } from 'vitest';
import { selectPlural, toStrings, Translator } from '../src/i18n.ts';
import { englishStrings, langStrings } from './support/dom.ts';

function keysOf(value: unknown, prefix = ''): string[] {
    if (typeof value !== 'object' || value === null) {
        return [prefix];
    }
    return Object.entries(value).flatMap(([key, child]) =>
        keysOf(child, prefix === '' ? key : `${prefix}.${key}`),
    );
}

describe('plural strings (Laravel trans_choice syntax)', () => {
    it.each([
        ['{1} one|[2,*] many', 1, 'one'],
        ['{1} one|[2,*] many', 2, 'many'],
        ['{1} one|[2,*] many', 0, 'many'],
        ['{0} none|{1} one|[2,*] many', 0, 'none'],
        ['{0} none|[1,19] some|[20,*] lots', 7, 'some'],
        ['{0} none|[1,19] some|[20,*] lots', 20, 'lots'],
        ['[*,0] none or less|[1,*] some', -1, 'none or less'],
        ['apple|apples', 1, 'apple'],
        ['apple|apples', 3, 'apples'],
        ['only', 5, 'only'],
    ])('%s with %d → %s', (line, count, expected) => {
        expect(selectPlural(line, count)).toBe(expected);
    });

    it('fills :count and other placeholders, and never says "1 problems"', () => {
        const tr = new Translator(toStrings(englishStrings()));
        expect(tr.choice('lint.count', 1)).toBe('1 problem');
        expect(tr.choice('lint.count', 3)).toBe('3 problems');
        expect(tr.choice('announce.invalid', 1)).toBe('Not saved: 1 structure error.');
        expect(tr.choice('announce.invalid', 2)).toBe('Not saved: 2 structure errors.');
        expect(tr.choice('announce.lint_found', 0)).toBe('No problems found.');
        expect(tr.choice('announce.lint_found', 1)).toBe('1 problem found.');
        const de = new Translator(toStrings({ ui: langStrings('de') }));
        expect(de.choice('lint.count', 1)).toBe('1 Problem');
        expect(de.choice('announce.lint_found', 3)).toBe('3 Probleme gefunden.');
        expect(de.choice('announce.invalid', 1)).toBe('Nicht gespeichert: 1 Strukturfehler.');
    });

    it('keeps the German and English builder strings in step', () => {
        expect(keysOf(langStrings('de')).sort()).toEqual(keysOf(langStrings('en')).sort());
    });
});
