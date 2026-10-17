/** UI strings embedded by the Blade page (`lang/{de,en}/builder_v2.php`, `forms.lint`, `forms.types`). */

export interface Strings {
    readonly ui: Readonly<Record<string, unknown>>;
    readonly lint: Readonly<Record<string, unknown>>;
    readonly types: Readonly<Record<string, unknown>>;
}

export type Params = Readonly<Record<string, string | number>>;

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Accepts anything (the embedded payload is external input) and keeps only usable maps. */
export function toStrings(value: unknown): Strings {
    const source = isRecord(value) ? value : {};
    const map = (key: string): Readonly<Record<string, unknown>> => {
        const member = source[key];
        return isRecord(member) ? member : {};
    };
    return { ui: map('ui'), lint: map('lint'), types: map('types') };
}

function lookup(root: Readonly<Record<string, unknown>>, key: string): string | null {
    let current: unknown = root;
    for (const part of key.split('.')) {
        current = isRecord(current) ? current[part] : undefined;
    }
    return typeof current === 'string' ? current : null;
}

/** Laravel-style `:name` placeholders; longer names first so `:count` never eats `:counter`. */
export function replaceParams(text: string, params: Params): string {
    return Object.keys(params)
        .sort((a, b) => b.length - a.length)
        .reduce((result, name) => result.split(`:${name}`).join(String(params[name])), text);
}

/** One Laravel `{n}` / `[a,b]` / `[a,*]` / `[*,b]` condition, or null when the part has none. */
function matchCondition(part: string, count: number): string | null {
    const match = /^[{[]([^[\]{}]*)[}\]]([\s\S]*)$/.exec(part);
    if (match === null) {
        return null;
    }
    const condition = match[1] ?? '';
    const text = match[2] ?? '';
    if (condition.includes(',')) {
        const [from = '', to = ''] = condition.split(',', 2).map((value) => value.trim());
        const above = from === '*' || count >= Number(from);
        const below = to === '*' || count <= Number(to);
        return above && below ? text : null;
    }
    return condition.trim() !== '' && Number(condition) === count ? text : null;
}

/**
 * Laravel's `trans_choice` selector for `{1} one|[2,*] many` (or plain `one|many`) strings: the
 * first part whose condition matches wins; otherwise the plural rule of en/de (1 → first part).
 */
export function selectPlural(line: string, count: number): string {
    const parts = line.split('|');
    for (const part of parts) {
        const text = matchCondition(part.trim(), count);
        if (text !== null) {
            return text.trim();
        }
    }
    const plain = parts.map((part) =>
        part
            .trim()
            .replace(/^[{[][^[\]{}]*[}\]]/, '')
            .trim(),
    );
    return (count === 1 ? plain[0] : (plain[1] ?? plain[0])) ?? line;
}

export class Translator {
    readonly #strings: Strings;

    constructor(strings: Strings) {
        this.#strings = strings;
    }

    /** A builder_v2 string; falls back to the key so a missing translation stays visible. */
    t(key: string, params: Params = {}): string {
        return replaceParams(lookup(this.#strings.ui, key) ?? key, params);
    }

    /** A pluralised builder_v2 string (`:count` is filled in). */
    choice(key: string, count: number, params: Params = {}): string {
        const line = lookup(this.#strings.ui, key) ?? key;
        return replaceParams(selectPlural(line, count), { count, ...params });
    }

    typeLabel(type: string): string {
        return lookup(this.#strings.types, type) ?? type;
    }

    lintLabel(code: string): string {
        return lookup(this.#strings.lint, code) ?? code;
    }
}
