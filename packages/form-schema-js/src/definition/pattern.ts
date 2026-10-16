/** `validation.pattern` handling (spec §3.4): restricted syntax, Unicode-mode ECMAScript semantics. */
import { translatePattern } from './pattern-syntax.ts';

/** Values longer than this many code points fail the `pattern` check (fail closed). */
export const MAX_PATTERN_SUBJECT = 4096;

const cache = new Map<string, RegExp | null>();

function compile(source: string): RegExp | null {
    try {
        return new RegExp(source, 'u');
    } catch {
        return null;
    }
}

/** True when the pattern is inside the §3.4 subset. */
export function isValidPattern(pattern: string): boolean {
    return compilePattern(pattern) !== null;
}

/** Compiles a valid pattern (implicitly unanchored, `u` flag only); `null` when invalid. */
export function compilePattern(pattern: string): RegExp | null {
    const cached = cache.get(pattern);
    if (cached !== undefined) {
        return cached;
    }
    const source = translatePattern(pattern);
    const compiled = source === null ? null : compile(source);
    cache.set(pattern, compiled);
    return compiled;
}

/**
 * Applies a pattern constraint: `true` when the value matches. Invalid patterns impose no
 * constraint; values over 4,096 code points fail.
 */
export function matchesPattern(pattern: string, value: string): boolean {
    const regex = compilePattern(pattern);
    if (regex === null) {
        return true;
    }
    return Array.from(value).length <= MAX_PATTERN_SUBJECT && regex.test(value);
}
