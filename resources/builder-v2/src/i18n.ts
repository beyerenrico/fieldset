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

export class Translator {
    readonly #strings: Strings;

    constructor(strings: Strings) {
        this.#strings = strings;
    }

    /** A builder_v2 string; falls back to the key so a missing translation stays visible. */
    t(key: string, params: Params = {}): string {
        return replaceParams(lookup(this.#strings.ui, key) ?? key, params);
    }

    typeLabel(type: string): string {
        return lookup(this.#strings.types, type) ?? type;
    }

    lintLabel(code: string): string {
        return lookup(this.#strings.lint, code) ?? code;
    }
}
