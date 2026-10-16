/** Numbering format rules (spec §7.3). */
import { objectProp, stringProp, type RawObject } from '../json/types.ts';
import { finding, type LintFinding } from './types.ts';

const TOKEN = /\{(YYYY|YY|MM|SEQ:(?:1[0-2]|[1-9]))\}|[A-Za-z0-9._/-]+/y;

/** Token names of a format (`SEQ` for any width, `literal` for text), or `null` when malformed. */
export function tokenizeNumbering(format: string): string[] | null {
    const tokens: string[] = [];
    let position = 0;
    while (position < format.length) {
        TOKEN.lastIndex = position;
        const match = TOKEN.exec(format);
        if (match === null) {
            return null;
        }
        const name = match[1];
        tokens.push(name === undefined ? 'literal' : name.startsWith('SEQ') ? 'SEQ' : name);
        position = TOKEN.lastIndex;
    }
    return tokens;
}

/** True when `format` is valid for the given `reset` policy. */
export function isValidNumbering(format: string, reset: string): boolean {
    const tokens = tokenizeNumbering(format);
    if (tokens === null || tokens.filter((token) => token === 'SEQ').length !== 1) {
        return false;
    }
    const hasYear = tokens.includes('YYYY') || tokens.includes('YY');
    if (reset === 'yearly') {
        return hasYear;
    }
    return reset !== 'monthly' || (hasYear && tokens.includes('MM'));
}

export function numberingFindings(definition: RawObject): LintFinding[] {
    const numbering = objectProp(objectProp(definition, 'settings') ?? {}, 'numbering');
    const format = numbering === undefined ? undefined : stringProp(numbering, 'format');
    if (numbering === undefined || format === undefined) {
        return [];
    }
    const reset = stringProp(numbering, 'reset') ?? 'never';
    return isValidNumbering(format, reset)
        ? []
        : [finding('invalid_numbering_format', '/settings/numbering/format', { format, reset })];
}
