/** Key scopes for the inspector (logic editor, key suggestions) and for unique keys on insert. */
import type { JsonObject } from '@fieldset/fieldset-js';
import type { Container, InspectorScope } from '../contract.ts';
import type { Selection } from './history.ts';
import { isObject, listProp, str } from './json.ts';
import { nodeEntry, repeaterAncestors, typeOf, type Definition } from './tree.ts';

/** Element types without data (spec §3.2); they carry a key but no value. */
const LAYOUT_TYPES: ReadonlySet<string> = new Set([
    'section',
    'columns',
    'heading',
    'paragraph',
    'divider',
    'image',
    'page_break',
]);

const MAX_KEY_LENGTH = 64;

export function isDataElement(node: JsonObject): boolean {
    const type = typeOf(node);
    return type !== '' && !LAYOUT_TYPES.has(type);
}

/** Direct children of an element or page: `elements` plus every column's elements. */
function childrenOf(node: JsonObject): JsonObject[] {
    const columns = listProp(node, 'columns').flatMap((column) =>
        isObject(column) ? listProp(column, 'elements') : [],
    );
    return [...listProp(node, 'elements'), ...columns].filter(isObject);
}

/** Elements whose key lives in the scope of `owners` (layout containers are transparent). */
function scopeMembers(owners: readonly JsonObject[]): JsonObject[] {
    const found: JsonObject[] = [];
    const visit = (node: JsonObject): void => {
        found.push(node);
        if (typeOf(node) !== 'repeater') {
            childrenOf(node).forEach(visit);
        }
    };
    owners.forEach((owner) => {
        childrenOf(owner).forEach(visit);
    });
    return found;
}

function keysOf(nodes: readonly JsonObject[], dataOnly: boolean): string[] {
    const keys = nodes
        .filter((node) => !dataOnly || isDataElement(node))
        .map((node) => str(node.key))
        .filter((key): key is string => key !== null && key !== '');
    return [...new Set(keys)];
}

function pages(definition: Definition): JsonObject[] {
    return listProp(definition, 'pages').filter(isObject);
}

function allElements(definition: Definition): JsonObject[] {
    const found: JsonObject[] = [];
    const visit = (node: JsonObject): void => {
        found.push(node);
        childrenOf(node).forEach(visit);
    };
    pages(definition).forEach((page) => {
        childrenOf(page).forEach(visit);
    });
    return found;
}

/** The scope owners of a node: its nearest enclosing repeater, or all pages (root scope). */
function scopeOwnersOf(definition: Definition, id: string | null): JsonObject[] {
    const repeaterId = id === null ? undefined : repeaterAncestors(definition, id).at(-1);
    const repeater = repeaterId === undefined ? null : nodeEntry(definition, repeaterId);
    return repeater === null ? pages(definition) : [repeater.node];
}

/** A calculated value with `format: text` compares like text (v1 KeyTypes parity). */
function effectiveType(node: JsonObject): string {
    const type = typeOf(node);
    return type === 'calculated' && str(node.format) === 'text' ? 'text' : type;
}

export function inspectorScope(
    definition: Definition,
    selection: Selection,
    autoKeyIds: ReadonlySet<string>,
): InspectorScope {
    const elements = allElements(definition);
    const keyTypes: Record<string, string> = {};
    const rowKeys: Record<string, readonly string[]> = {};
    for (const node of elements) {
        const key = str(node.key);
        if (key === null || key === '' || key in keyTypes) {
            continue;
        }
        keyTypes[key] = effectiveType(node);
        if (typeOf(node) === 'repeater') {
            rowKeys[key] = keysOf(scopeMembers([node]), true);
        }
    }
    const id = selection?.kind === 'element' ? selection.id : null;
    return {
        keyTypes,
        rowKeys,
        scopeKeys: keysOf(scopeMembers(scopeOwnersOf(definition, id)), false),
        autoKey: id !== null && autoKeyIds.has(id),
    };
}

/** Every key (layout included) already used in the scope a list belongs to. */
export function keysInScopeOf(definition: Definition, container: Container): Set<string> {
    if (container.kind === 'pages') {
        return new Set(pages(definition).map((page) => str(page.key) ?? ''));
    }
    if (container.kind === 'page') {
        return new Set(keysOf(scopeMembers(pages(definition)), false));
    }
    const parent = nodeEntry(definition, container.parentId);
    if (parent !== null && typeOf(parent.node) === 'repeater') {
        return new Set(keysOf(scopeMembers([parent.node]), false));
    }
    return new Set(keysOf(scopeMembers(scopeOwnersOf(definition, container.parentId)), false));
}

/** `base`, or `base_2`, `base_3`, … (kept within 64 characters) when taken. */
export function uniqueKey(base: string, taken: ReadonlySet<string>): string {
    if (!taken.has(base)) {
        return base;
    }
    for (let counter = 2; ; counter++) {
        const suffix = `_${String(counter)}`;
        const candidate = base.slice(0, MAX_KEY_LENGTH - suffix.length) + suffix;
        if (!taken.has(candidate)) {
            return candidate;
        }
    }
}
