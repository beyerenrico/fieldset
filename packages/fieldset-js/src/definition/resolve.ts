/** Static resolution of `var` paths through the scope chain (spec §4.3). */
import { stringProp } from '../json/types.ts';
import type { ElementNode, ScopeNode } from './model.ts';

export type Resolution =
    | { readonly kind: 'builtin' }
    | { readonly kind: 'scope' }
    | { readonly kind: 'element'; readonly node: ElementNode; readonly rest: readonly string[] }
    | { readonly kind: 'unknown' };

/** Built-in names and their known members (`null`: no members). */
const BUILTINS: ReadonlyMap<string, readonly string[] | null> = new Map([
    ['$today', null],
    ['$now', null],
    ['$user', ['name', 'email']],
    ['$org', ['name']],
    ['$form', ['version']],
]);

const UNKNOWN: Resolution = { kind: 'unknown' };
const ARRAY_INDEX = /^(0|[1-9][0-9]*)$/;

export function rootScopeOf(scope: ScopeNode): ScopeNode {
    return scope.parent === null ? scope : rootScopeOf(scope.parent);
}

function resolveBuiltin(members: readonly string[] | null, rest: readonly string[]): Resolution {
    if (rest.length === 0) {
        return { kind: 'builtin' };
    }
    const [member] = rest;
    return members !== null && rest.length === 1 && member !== undefined && members.includes(member)
        ? { kind: 'builtin' }
        : UNKNOWN;
}

function resolveIn(scope: ScopeNode, rest: readonly string[]): Resolution {
    const [key, ...deeper] = rest;
    if (key === undefined) {
        return { kind: 'scope' };
    }
    const node = scope.dataKeys.get(key);
    return node ? { kind: 'element', node, rest: deeper } : UNKNOWN;
}

function resolveChain(scope: ScopeNode | null, key: string, rest: readonly string[]): Resolution {
    if (scope === null) {
        return UNKNOWN;
    }
    const node = scope.dataKeys.get(key);
    return node ? { kind: 'element', node, rest } : resolveChain(scope.parent, key, rest);
}

/**
 * Resolves a dotted path for an element living in `scope`: built-ins, `$root.…`, `$row.…`, then
 * row keys shadowing outer keys up to the root scope. Only the first data key is checked; deeper
 * segments are returned in `rest`.
 */
export function resolveReference(path: string, scope: ScopeNode): Resolution {
    if (path === '') {
        return { kind: 'scope' };
    }
    const [first = '', ...rest] = path.split('.');
    const builtin = BUILTINS.get(first);
    if (builtin !== undefined) {
        return resolveBuiltin(builtin, rest);
    }
    if (first === '$root') {
        return resolveIn(rootScopeOf(scope), rest);
    }
    if (first === '$row') {
        return scope.repeater === null ? UNKNOWN : resolveIn(scope, rest);
    }
    return resolveChain(scope, first, rest);
}

/** Follows `rest` through repeater rows (`items.0.qty`); `null` for sub-fields or scopes. */
export function targetElement(resolution: Resolution): ElementNode | null {
    if (resolution.kind !== 'element') {
        return null;
    }
    const { node, rest } = resolution;
    if (rest.length === 0) {
        return node;
    }
    const [index = '', key = ''] = rest;
    const row = node.rowScope;
    if (row === null || !ARRAY_INDEX.test(index)) {
        return null;
    }
    const child = row.dataKeys.get(key);
    return child ? targetElement({ kind: 'element', node: child, rest: rest.slice(2) }) : null;
}

/** Number, money, or calculated element with numeric format (default `number`). */
export function isNumericElement(node: ElementNode): boolean {
    if (node.type === 'number' || node.type === 'money') {
        return true;
    }
    return node.type === 'calculated' && (stringProp(node.raw, 'format') ?? 'number') !== 'text';
}

/** All calculated elements inside a repeater's rows (nested repeaters included). */
export function calculatedWithin(repeater: ElementNode): ElementNode[] {
    const scope = repeater.rowScope;
    if (scope === null) {
        return [];
    }
    return scope.elements.flatMap((node) => [
        ...(node.type === 'calculated' ? [node] : []),
        ...(node.type === 'repeater' ? calculatedWithin(node) : []),
    ]);
}
