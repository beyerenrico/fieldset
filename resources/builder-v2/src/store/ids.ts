/** UUIDv7 ids for new nodes and id normalization of loaded definitions. */
import type { JsonObject, JsonValue } from '@fieldset/fieldset-js';
import { isObject, listProp, str } from './json.ts';

export type RandomFill = (bytes: Uint8Array<ArrayBuffer>) => Uint8Array<ArrayBuffer>;

const defaultRandom: RandomFill = (bytes) => crypto.getRandomValues(bytes);

/** RFC 9562 UUIDv7: 48-bit Unix milliseconds, version 7, variant 10, random rest. */
export function uuidv7(now: number = Date.now(), random: RandomFill = defaultRandom): string {
    const bytes = random(new Uint8Array(16));
    let time = Math.max(0, Math.floor(now));
    for (let index = 5; index >= 0; index--) {
        bytes[index] = time % 256;
        time = Math.floor(time / 256);
    }
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x70;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export type IdFactory = () => string;

/** A copy of a palette/cloned subtree where every element (and identified column) gets a new id. */
export function withFreshIds(node: JsonObject, newId: IdFactory = () => uuidv7()): JsonObject {
    const copy: Record<string, JsonValue> = { ...node, id: newId() };
    if (Array.isArray(node.elements)) {
        copy.elements = listProp(node, 'elements').map((child) =>
            isObject(child) ? withFreshIds(child, newId) : child,
        );
    }
    if (Array.isArray(node.columns)) {
        copy.columns = listProp(node, 'columns').map((column) => {
            if (!isObject(column)) {
                return column;
            }
            const elements = listProp(column, 'elements').map((child) =>
                isObject(child) ? withFreshIds(child, newId) : child,
            );
            return str(column.id) === null
                ? { ...column, elements }
                : { ...column, id: newId(), elements };
        });
    }
    return copy;
}

function unchanged(next: readonly JsonValue[], list: readonly JsonValue[]): boolean {
    return next.every((item, index) => item === list[index]);
}

interface NormalizeContext {
    readonly seen: Set<string>;
    readonly newId: IdFactory;
}

function normalizeList(list: readonly JsonValue[], ctx: NormalizeContext): readonly JsonValue[] {
    const next = list.map((item) => (isObject(item) ? normalizeNode(item, ctx) : item));
    return unchanged(next, list) ? list : next;
}

function normalizeColumns(
    columns: readonly JsonValue[],
    ctx: NormalizeContext,
): readonly JsonValue[] {
    const next = columns.map((column) => {
        if (!isObject(column) || !Array.isArray(column.elements)) {
            return column;
        }
        const elements = normalizeList(listProp(column, 'elements'), ctx);
        return elements === column.elements ? column : { ...column, elements };
    });
    return unchanged(next, columns) ? columns : next;
}

/** Gives a node a fresh id when it has none or repeats an earlier id; children likewise. */
function normalizeNode(node: JsonObject, ctx: NormalizeContext): JsonObject {
    const id = str(node.id);
    const keep = id !== null && id !== '' && !ctx.seen.has(id);
    const finalId = keep ? id : ctx.newId();
    ctx.seen.add(finalId);
    let result: JsonObject = keep ? node : { ...node, id: finalId };
    if (Array.isArray(node.elements)) {
        const elements = normalizeList(listProp(node, 'elements'), ctx);
        result = elements === node.elements ? result : { ...result, elements };
    }
    if (Array.isArray(node.columns)) {
        const columns = normalizeColumns(listProp(node, 'columns'), ctx);
        result = columns === node.columns ? result : { ...result, columns };
    }
    return result;
}

/**
 * Makes every page and element addressable: missing or duplicate ids get a UUIDv7. A valid
 * definition is returned unchanged (same object), so load + serialize is lossless.
 */
export function normalizeIds(
    definition: JsonObject,
    newId: IdFactory = () => uuidv7(),
): JsonObject {
    if (!Array.isArray(definition.pages)) {
        return definition;
    }
    const pages = normalizeList(listProp(definition, 'pages'), { seen: new Set(), newId });
    return pages === definition.pages ? definition : { ...definition, pages };
}
