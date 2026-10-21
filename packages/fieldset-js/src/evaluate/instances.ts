/** Element instances: definition nodes located in the data (one per repeater row). */
import type { DefinitionModel, ElementNode, ScopeNode } from '../definition/model.ts';
import { toPointer } from '../json/pointer.ts';
import type { JsonValue } from '../json/types.ts';
import { getAt, type DataPath } from './data.ts';

/** One repeater row enclosing an instance. */
export interface RowRef {
    readonly scope: ScopeNode;
    readonly path: DataPath;
    readonly pointer: string;
}

export interface Located {
    readonly node: ElementNode;
    /** Data path of the element's value (layout elements: where the key would live). */
    readonly path: DataPath;
    readonly pointer: string;
    /** Enclosing rows, outermost first. */
    readonly rows: readonly RowRef[];
}

export interface Instance extends Located {
    /** Pointer of the enclosing section/columns/repeater instance, `null` at page level. */
    readonly parentPointer: string | null;
}

interface WalkContext {
    readonly base: DataPath;
    readonly rows: readonly RowRef[];
    readonly parentPointer: string | null;
}

function rowsOf(data: JsonValue, path: DataPath): readonly unknown[] {
    const value = getAt(data, path);
    return Array.isArray(value) ? (value as readonly unknown[]) : [];
}

function collect(nodes: readonly ElementNode[], context: WalkContext, data: JsonValue): Instance[] {
    return nodes.flatMap((node) => {
        const path = [...context.base, node.key];
        const pointer = toPointer(path);
        const self: Instance = {
            node,
            path,
            pointer,
            rows: context.rows,
            parentPointer: context.parentPointer,
        };
        if (node.rowScope !== null) {
            const scope = node.rowScope;
            const nested = rowsOf(data, path).flatMap((_row, index) => {
                const rowPath = [...path, index];
                const row: RowRef = { scope, path: rowPath, pointer: toPointer(rowPath) };
                return collect(
                    node.children,
                    { base: rowPath, rows: [...context.rows, row], parentPointer: pointer },
                    data,
                );
            });
            return [self, ...nested];
        }
        return [self, ...collect(node.children, { ...context, parentPointer: pointer }, data)];
    });
}

const listed = new WeakMap<object, WeakMap<DefinitionModel, readonly Instance[]>>();

/**
 * Every element instance in document order; repeater rows in index order. Memoized per data object:
 * data is copy-on-write (data.ts), so the same object always has the same rows; one evaluation lists
 * the instances of the same state several times (M8-T05).
 */
export function listInstances(model: DefinitionModel, data: JsonValue): readonly Instance[] {
    const byModel = typeof data === 'object' && data !== null ? listed.get(data) : undefined;
    const cached = byModel?.get(model);
    if (cached !== undefined) {
        return cached;
    }
    const instances = model.pages.flatMap((page) =>
        collect(page.elements, { base: [], rows: [], parentPointer: null }, data),
    );
    if (typeof data === 'object' && data !== null) {
        listed.set(data, (byModel ?? new WeakMap()).set(model, instances));
    }
    return instances;
}

/** Row chains for a scope: one chain per existing row combination of the enclosing repeaters. */
function rowChains(scope: ScopeNode, data: JsonValue): RowRef[][] {
    if (scope.repeater === null || scope.parent === null) {
        return [[]];
    }
    const { repeater, parent } = scope;
    return rowChains(parent, data).flatMap((chain) => {
        const base = chain.at(-1)?.path ?? [];
        const path = [...base, repeater.key];
        return rowsOf(data, path).map((_row, index) => {
            const rowPath = [...path, index];
            return [...chain, { scope, path: rowPath, pointer: toPointer(rowPath) }];
        });
    });
}

/** All instances of one element (every row of its enclosing repeaters). */
export function instancesOf(node: ElementNode, data: JsonValue): Located[] {
    return rowChains(node.scope, data).map((rows) => {
        const path = [...(rows.at(-1)?.path ?? []), node.key];
        return { node, path, pointer: toPointer(path), rows };
    });
}
