/** `validation.pattern` handling (spec §3.3): ECMAScript syntax without lookbehind/backreferences. */

const cache = new Map<string, RegExp | null>();

/** True when the pattern uses lookbehind or backreferences outside character classes. */
export function usesForbiddenSyntax(source: string): boolean {
    let inClass = false;
    for (let index = 0; index < source.length; index++) {
        const char = source[index];
        if (char === '\\') {
            const next = source[index + 1] ?? '';
            if (!inClass && /[1-9k]/.test(next)) {
                return true;
            }
            index++;
        } else if (inClass) {
            inClass = char !== ']';
        } else if (char === '[') {
            inClass = true;
        } else if (source.startsWith('(?<=', index) || source.startsWith('(?<!', index)) {
            return true;
        }
    }
    return false;
}

function tryCompile(source: string, flags: string): RegExp | null {
    try {
        return new RegExp(source, flags);
    } catch {
        return null;
    }
}

/**
 * Compiles an implicitly unanchored pattern (Unicode mode when valid there, otherwise legacy
 * mode). Returns `null` for invalid or forbidden patterns.
 */
export function compilePattern(source: string): RegExp | null {
    const cached = cache.get(source);
    if (cached !== undefined) {
        return cached;
    }
    const compiled = usesForbiddenSyntax(source)
        ? null
        : (tryCompile(source, 'u') ?? tryCompile(source, ''));
    cache.set(source, compiled);
    return compiled;
}
