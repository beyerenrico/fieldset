/**
 * Validator and normalizer for the restricted pattern syntax of spec §3.4. A valid pattern is
 * re-emitted as an equivalent ECMAScript source for `new RegExp(source, 'u')` (the only
 * difference: `\-` outside a class becomes `-`, which Unicode mode does not allow escaped).
 */

const SYNTAX_CHARS = new Set([
    '^',
    '$',
    '\\',
    '.',
    '*',
    '+',
    '?',
    '(',
    ')',
    '[',
    ']',
    '{',
    '}',
    '|',
]);
const SET_ESCAPES = new Set(['d', 'D', 'w', 'W', 's', 'S']);
const CONTROL_ESCAPES: Readonly<Record<string, number>> = { t: 9, n: 10, v: 11, f: 12, r: 13 };
const MAX_BOUND = 1000;
const MAX_CODE_POINT = 0x10ffff;

class Invalid extends Error {}

/** Parsed piece of a pattern: emitted source plus what the nested-quantifier rule needs. */
interface Piece {
    readonly source: string;
    readonly hasQuantifier: boolean;
    readonly hasAlternation: boolean;
}

/** A class member: a single code point (usable in ranges) or a set escape such as `\d`. */
type ClassAtom =
    | { readonly kind: 'char'; readonly code: number; readonly source: string }
    | { readonly kind: 'set'; readonly source: string };

class Parser {
    private readonly chars: readonly string[];
    private index = 0;

    constructor(chars: readonly string[]) {
        this.chars = chars;
    }

    parse(): string {
        const piece = this.disjunction();
        this.require(this.index === this.chars.length);
        return piece.source;
    }

    private peek(offset = 0): string | undefined {
        return this.chars[this.index + offset];
    }

    private next(): string {
        const char = this.chars[this.index];
        this.require(char !== undefined);
        this.index++;
        return char;
    }

    private require(condition: boolean): asserts condition {
        if (!condition) {
            throw new Invalid();
        }
    }

    private disjunction(): Piece {
        const alternatives = [this.alternative()];
        while (this.peek() === '|') {
            this.index++;
            alternatives.push(this.alternative());
        }
        return {
            source: alternatives.map((piece) => piece.source).join('|'),
            hasQuantifier: alternatives.some((piece) => piece.hasQuantifier),
            hasAlternation:
                alternatives.length > 1 || alternatives.some((piece) => piece.hasAlternation),
        };
    }

    private alternative(): Piece {
        const terms: Piece[] = [];
        while (this.peek() !== undefined && this.peek() !== '|' && this.peek() !== ')') {
            terms.push(this.term());
        }
        return {
            source: terms.map((piece) => piece.source).join(''),
            hasQuantifier: terms.some((piece) => piece.hasQuantifier),
            hasAlternation: terms.some((piece) => piece.hasAlternation),
        };
    }

    private term(): Piece {
        const assertion = this.assertion();
        if (assertion !== null) {
            this.require(!this.atQuantifier());
            return assertion;
        }
        const { piece, isGroup } = this.atom();
        const quantifier = this.quantifier();
        if (quantifier === null) {
            return piece;
        }
        const nested = piece.hasQuantifier || piece.hasAlternation;
        this.require(!(isGroup && nested && !quantifier.optionalOnly));
        this.require(!this.atQuantifier());
        return { ...piece, source: piece.source + quantifier.source, hasQuantifier: true };
    }

    private assertion(): Piece | null {
        const char = this.peek();
        if (char === '^' || char === '$') {
            this.index++;
            return { source: char, hasQuantifier: false, hasAlternation: false };
        }
        if (char === '\\' && (this.peek(1) === 'b' || this.peek(1) === 'B')) {
            this.index += 2;
            return {
                source: `\\${this.peek(-1) ?? ''}`,
                hasQuantifier: false,
                hasAlternation: false,
            };
        }
        if (
            char === '(' &&
            this.peek(1) === '?' &&
            (this.peek(2) === '=' || this.peek(2) === '!')
        ) {
            const prefix = `(?${this.peek(2) ?? ''}`;
            this.index += 3;
            return this.groupBody(prefix);
        }
        return null;
    }

    private groupBody(prefix: string): Piece {
        const inner = this.disjunction();
        this.require(this.next() === ')');
        return { ...inner, source: `${prefix}${inner.source})` };
    }

    private atom(): { piece: Piece; isGroup: boolean } {
        const char = this.next();
        if (char === '(') {
            const nonCapturing = this.peek() === '?';
            if (nonCapturing) {
                this.require(this.peek(1) === ':');
                this.index += 2;
            }
            return { piece: this.groupBody(nonCapturing ? '(?:' : '('), isGroup: true };
        }
        const source = char === '[' ? this.characterClass() : this.simpleAtom(char);
        return { piece: { source, hasQuantifier: false, hasAlternation: false }, isGroup: false };
    }

    private simpleAtom(char: string): string {
        if (char === '.') {
            return '.';
        }
        if (char === '\\') {
            const escape = this.escape();
            return escape.kind === 'char' && escape.source === '\\-' ? '-' : escape.source;
        }
        this.require(!SYNTAX_CHARS.has(char));
        return char;
    }

    private atQuantifier(): boolean {
        const char = this.peek();
        return char === '*' || char === '+' || char === '?' || char === '{';
    }

    private quantifier(): { source: string; optionalOnly: boolean } | null {
        const char = this.peek();
        if (char === '*' || char === '+' || char === '?') {
            this.index++;
            return this.lazy(char, char === '?');
        }
        if (char !== '{') {
            return null;
        }
        this.index++;
        const min = this.digits();
        let max: number | null = min;
        if (this.peek() === ',') {
            this.index++;
            max = this.peek() === '}' ? null : this.digits();
        }
        this.require(
            this.next() === '}' &&
                min <= MAX_BOUND &&
                (max === null || (min <= max && max <= MAX_BOUND)),
        );
        const source =
            max === min
                ? `{${String(min)}}`
                : `{${String(min)},${max === null ? '' : String(max)}}`;
        return this.lazy(source, min === 0 && max === 1);
    }

    private lazy(source: string, optionalOnly: boolean): { source: string; optionalOnly: boolean } {
        if (this.peek() === '?') {
            this.index++;
            return { source: `${source}?`, optionalOnly };
        }
        return { source, optionalOnly };
    }

    private digits(): number {
        let text = '';
        while (/^[0-9]$/.test(this.peek() ?? '')) {
            text += this.next();
        }
        this.require(text !== '');
        return Number(text);
    }

    private characterClass(): string {
        const negated = this.peek() === '^';
        if (negated) {
            this.index++;
        }
        let body = '';
        while (this.peek() !== ']') {
            body += this.classMember();
        }
        this.index++;
        return `[${negated ? '^' : ''}${body}]`;
    }

    private classMember(): string {
        const left = this.classAtom();
        if (this.peek() !== '-' || this.peek(1) === ']' || this.peek(1) === undefined) {
            return left.source;
        }
        this.index++;
        const right = this.classAtom();
        this.require(left.kind === 'char' && right.kind === 'char' && left.code <= right.code);
        return `${left.source}-${right.source}`;
    }

    private classAtom(): ClassAtom {
        const char = this.next();
        if (char === '\\') {
            this.require(this.peek() !== 'b' && this.peek() !== 'B');
            return this.escape();
        }
        this.require(char !== '[');
        const code = char.codePointAt(0) ?? 0;
        return { kind: 'char', code, source: char === '-' || char === '^' ? `\\${char}` : char };
    }

    /** Escape after a consumed backslash (`\b`/`\B` are handled by the caller). */
    private escape(): ClassAtom {
        const char = this.next();
        if (SET_ESCAPES.has(char)) {
            return { kind: 'set', source: `\\${char}` };
        }
        const control = CONTROL_ESCAPES[char];
        if (control !== undefined) {
            return { kind: 'char', code: control, source: `\\${char}` };
        }
        if (char === '0') {
            this.require(!/^[0-9]$/.test(this.peek() ?? ''));
            return { kind: 'char', code: 0, source: '\\0' };
        }
        if (char === 'x' || char === 'u') {
            return this.hexEscape(char);
        }
        this.require(SYNTAX_CHARS.has(char) || char === '/' || char === '-');
        return { kind: 'char', code: char.codePointAt(0) ?? 0, source: `\\${char}` };
    }

    private hexEscape(kind: 'x' | 'u'): ClassAtom {
        if (kind === 'u' && this.peek() === '{') {
            this.index++;
            let hex = '';
            while (this.peek() !== '}') {
                hex += this.hexDigit();
            }
            this.index++;
            const code = parseInt(hex, 16);
            this.require(hex !== '' && code <= MAX_CODE_POINT);
            return { kind: 'char', code, source: `\\u{${hex}}` };
        }
        const width = kind === 'x' ? 2 : 4;
        let hex = '';
        for (let count = 0; count < width; count++) {
            hex += this.hexDigit();
        }
        return { kind: 'char', code: parseInt(hex, 16), source: `\\${kind}${hex}` };
    }

    private hexDigit(): string {
        const char = this.next();
        this.require(/^[0-9A-Fa-f]$/.test(char));
        return char;
    }
}

/**
 * Validates a pattern against the §3.4 subset and returns the normalized ECMAScript source, or
 * `null` when the pattern is outside the subset.
 */
export function translatePattern(pattern: string): string | null {
    try {
        return new Parser(Array.from(pattern)).parse();
    } catch (error) {
        if (error instanceof Invalid || error instanceof RangeError) {
            return null;
        }
        throw error;
    }
}
