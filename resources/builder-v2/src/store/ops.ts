/**
 * Pure operations over the canonical definition (ADR 0037). Every operation path-copies only the
 * branch it changes (structural sharing) and never rebuilds a node field by field, so unknown and
 * `x-*` properties survive untouched.
 */
import type { JsonObject, JsonValue } from '@form-schema-app/form-schema-js';
import type { Container, InspectorKind, Operation } from '../contract.ts';
import { getIn, isList, isObject, listProp, omit, setIn } from './json.ts';
import {
    MAX_COLUMNS,
    MIN_COLUMNS,
    listEntry,
    nodeEntry,
    typeOf,
    type Definition,
    type NodeEntry,
} from './tree.ts';

export class OperationError extends Error {
    override readonly name = 'OperationError';
}

function asDefinition(value: JsonValue): Definition {
    if (!isObject(value)) {
        throw new OperationError('Operation produced a non-object definition');
    }
    return value;
}

function requireNode(definition: Definition, id: string): NodeEntry {
    const entry = nodeEntry(definition, id);
    if (entry === null) {
        throw new OperationError(`Unknown node ${id}`);
    }
    return entry;
}

function listAt(
    definition: Definition,
    target: Container,
): { path: readonly (string | number)[]; list: readonly JsonValue[] } {
    const entry = listEntry(definition, target);
    if (entry === null) {
        throw new OperationError(`Unknown container ${JSON.stringify(target)}`);
    }
    const list = getIn(definition, entry.path);
    return { path: entry.path, list: isList(list) ? list : [] };
}

export function insertNode(
    definition: Definition,
    target: Container,
    index: number,
    node: JsonObject,
): Definition {
    const { path, list } = listAt(definition, target);
    if (!Number.isInteger(index) || index < 0 || index > list.length) {
        throw new OperationError(`Index ${String(index)} out of range`);
    }
    const next = [...list.slice(0, index), node, ...list.slice(index)];
    return asDefinition(setIn(definition, path, next));
}

export function removeNode(definition: Definition, id: string): Definition {
    const entry = requireNode(definition, id);
    const { path, list } = listAt(definition, entry.container);
    const next = list.filter((_, position) => position !== entry.index);
    return asDefinition(setIn(definition, path, next));
}

/** `index` is the final position after the node was removed from its old place. */
export function moveNode(
    definition: Definition,
    id: string,
    target: Container,
    index: number,
): Definition {
    const entry = requireNode(definition, id);
    return insertNode(removeNode(definition, id), target, index, entry.node);
}

function childrenOf(node: JsonObject, keys: readonly string[]): JsonObject {
    return Object.fromEntries(
        keys
            .filter((key) => Object.prototype.hasOwnProperty.call(node, key))
            .map((key) => [key, node[key] ?? null]),
    );
}

/** Replaces the own properties of a node; its children (`elements`/`columns`/`pages`) are kept. */
export function updateNode(
    definition: Definition,
    kind: InspectorKind,
    id: string | null,
    props: JsonObject,
): Definition {
    if (kind === 'settings') {
        return { ...omit(props, ['pages']), ...childrenOf(definition, ['pages']) };
    }
    if (id === null) {
        throw new OperationError('Page and element updates need an id');
    }
    const entry = requireNode(definition, id);
    if (entry.kind !== kind) {
        throw new OperationError(`Node ${id} is not a ${kind}`);
    }
    const children = kind === 'page' ? ['elements'] : ['elements', 'columns'];
    const next: JsonObject = {
        id,
        ...omit(props, [...children, 'id']),
        ...childrenOf(entry.node, children),
    };
    return asDefinition(setIn(definition, entry.path, next));
}

function columnsOf(
    definition: Definition,
    parentId: string,
): { entry: NodeEntry; columns: readonly JsonValue[] } {
    const entry = requireNode(definition, parentId);
    if (typeOf(entry.node) !== 'columns') {
        throw new OperationError(`Node ${parentId} is not a columns element`);
    }
    return { entry, columns: listProp(entry.node, 'columns') };
}

export function addColumn(definition: Definition, parentId: string, index: number): Definition {
    const { entry, columns } = columnsOf(definition, parentId);
    if (
        columns.length >= MAX_COLUMNS ||
        !Number.isInteger(index) ||
        index < 0 ||
        index > columns.length
    ) {
        throw new OperationError('Cannot add a column here');
    }
    const next = [...columns.slice(0, index), { elements: [] }, ...columns.slice(index)];
    return asDefinition(setIn(definition, [...entry.path, 'columns'], next));
}

/** Removes a column; its elements move to the left neighbour (or the start of the right one). */
export function removeColumn(definition: Definition, parentId: string, column: number): Definition {
    const { entry, columns } = columnsOf(definition, parentId);
    const removed = columns[column];
    if (columns.length <= MIN_COLUMNS || removed === undefined) {
        throw new OperationError('Cannot remove this column');
    }
    const moving = isObject(removed) ? listProp(removed, 'elements') : [];
    const neighbour = column > 0 ? column - 1 : column + 1;
    const merged = columns.map((item, position) => {
        if (position !== neighbour || !isObject(item)) {
            return item;
        }
        const own = listProp(item, 'elements');
        return { ...item, elements: column > 0 ? [...own, ...moving] : [...moving, ...own] };
    });
    const next = merged.filter((_, position) => position !== column);
    return asDefinition(setIn(definition, [...entry.path, 'columns'], next));
}

export function applyOperation(definition: Definition, op: Operation): Definition {
    switch (op.op) {
        case 'insert':
            return insertNode(definition, op.target, op.index, op.node);
        case 'move':
            return moveNode(definition, op.id, op.target, op.index);
        case 'remove':
            return removeNode(definition, op.id);
        case 'update':
            return updateNode(definition, op.kind, op.id, op.props);
        case 'addColumn':
            return addColumn(definition, op.parentId, op.index);
        case 'removeColumn':
            return removeColumn(definition, op.parentId, op.column);
    }
}
