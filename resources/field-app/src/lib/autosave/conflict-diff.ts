/** Top-level differences between my draft and the server copy, for the conflict panel. */
import {
    parseDefinition,
    type FormDefinition,
    type JsonObject,
    type JsonValue,
} from '@fieldset/fieldset-js';

export interface FieldDiff {
    readonly key: string;
    readonly label: string;
    readonly mine: JsonValue | undefined;
    readonly server: JsonValue | undefined;
}

function same(a: JsonValue | undefined, b: JsonValue | undefined): boolean {
    return JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
}

export function diffDrafts(
    definition: FormDefinition,
    mine: JsonObject,
    server: JsonObject,
): FieldDiff[] {
    const model = parseDefinition(definition);
    return [...model.root.dataKeys]
        .filter(([, node]) => node.type !== 'calculated')
        .flatMap(([key, node]) => {
            const left = mine[key];
            const right = server[key];
            if (same(left, right)) {
                return [];
            }
            const label = typeof node.raw.label === 'string' ? node.raw.label : key;
            return [{ key, label, mine: left, server: right }];
        });
}

export interface SummaryTexts {
    readonly empty: string;
    readonly yes: string;
    readonly no: string;
    readonly rows: (count: number) => string;
}

const MAX_LENGTH = 60;

/** Short human-readable form of a value; repeaters as row counts. */
export function summarize(value: JsonValue | undefined, texts: SummaryTexts): string {
    if (value === undefined || value === null || value === '') {
        return texts.empty;
    }
    if (typeof value === 'boolean') {
        return value ? texts.yes : texts.no;
    }
    if (Array.isArray(value)) {
        const objects = value.every((item) => typeof item === 'object' && item !== null);
        return objects && value.length > 0
            ? texts.rows(value.length)
            : value.join(', ') || texts.empty;
    }
    const text = typeof value === 'object' ? JSON.stringify(value) : String(value);
    return text.length > MAX_LENGTH ? `${text.slice(0, MAX_LENGTH - 1)}…` : text;
}
