/** Nesting rules (ADR 0037): where a page or element may go, and the column limits. */
import type { JsonObject } from '@fieldset/fieldset-js';
import type { Container, Operation } from '../contract.ts';
import { listProp } from './json.ts';
import {
    MAX_COLUMNS,
    MIN_COLUMNS,
    containerHeight,
    isWithin,
    listEntry,
    nodeEntry,
    typeOf,
    type Definition,
} from './tree.ts';

export type Refusal =
    | 'unknown_node'
    | 'unknown_target'
    | 'page_list_only'
    | 'not_in_page_list'
    | 'into_itself'
    | 'too_deep'
    | 'bad_index'
    | 'max_columns'
    | 'min_columns'
    | 'not_columns';

/** An existing node (by id) or a new one (palette, clone). */
export type DropSubject =
    { readonly id: string } | { readonly node: JsonObject; readonly page?: boolean };

interface ResolvedSubject {
    readonly id: string | null;
    readonly node: JsonObject;
    readonly isPage: boolean;
    readonly sameListAs: Container | null;
}

function resolve(definition: Definition, subject: DropSubject): ResolvedSubject | null {
    if ('id' in subject) {
        const entry = nodeEntry(definition, subject.id);
        return entry === null
            ? null
            : {
                  id: entry.id,
                  node: entry.node,
                  isPage: entry.kind === 'page',
                  sameListAs: entry.container,
              };
    }
    return { id: null, node: subject.node, isPage: subject.page === true, sameListAs: null };
}

function sameContainer(a: Container | null, b: Container): boolean {
    return a !== null && JSON.stringify(a) === JSON.stringify(b);
}

/**
 * Why `subject` may not go into `target` (null when it may). With `index`, the final position
 * (after removal from the old place) is checked too.
 */
export function canDrop(
    definition: Definition,
    subject: DropSubject,
    target: Container,
    maxContainerDepth: number,
    index?: number,
): Refusal | null {
    const resolved = resolve(definition, subject);
    if (resolved === null) {
        return 'unknown_node';
    }
    const list = listEntry(definition, target);
    if (list === null) {
        return 'unknown_target';
    }
    if (resolved.isPage !== (target.kind === 'pages')) {
        return resolved.isPage ? 'page_list_only' : 'not_in_page_list';
    }
    if (
        resolved.id !== null &&
        'parentId' in target &&
        isWithin(definition, target.parentId, resolved.id)
    ) {
        return 'into_itself';
    }
    const height = containerHeight(resolved.node);
    if (height > 0 && list.depth + height > maxContainerDepth) {
        return 'too_deep';
    }
    if (index === undefined) {
        return null;
    }
    const length = list.ids.length - (sameContainer(resolved.sameListAs, target) ? 1 : 0);
    return Number.isInteger(index) && index >= 0 && index <= length ? null : 'bad_index';
}

function columnCount(definition: Definition, parentId: string): number | null {
    const entry = nodeEntry(definition, parentId);
    return entry !== null && typeOf(entry.node) === 'columns'
        ? listProp(entry.node, 'columns').length
        : null;
}

export function canAddColumn(definition: Definition, parentId: string): Refusal | null {
    const count = columnCount(definition, parentId);
    if (count === null) {
        return 'not_columns';
    }
    return count >= MAX_COLUMNS ? 'max_columns' : null;
}

export function canRemoveColumn(
    definition: Definition,
    parentId: string,
    column: number,
): Refusal | null {
    const count = columnCount(definition, parentId);
    if (count === null) {
        return 'not_columns';
    }
    if (count <= MIN_COLUMNS) {
        return 'min_columns';
    }
    return Number.isInteger(column) && column >= 0 && column < count ? null : 'bad_index';
}

/** Validates an operation against the current definition before it is applied. */
export function checkOperation(
    definition: Definition,
    op: Operation,
    maxContainerDepth: number,
): Refusal | null {
    switch (op.op) {
        case 'insert':
            return canDrop(
                definition,
                { node: op.node, page: op.target.kind === 'pages' },
                op.target,
                maxContainerDepth,
                op.index,
            );
        case 'move':
            return canDrop(definition, { id: op.id }, op.target, maxContainerDepth, op.index);
        case 'remove':
            return nodeEntry(definition, op.id) === null ? 'unknown_node' : null;
        case 'update':
            return op.kind === 'settings' ||
                (op.id !== null && nodeEntry(definition, op.id)?.kind === op.kind)
                ? null
                : 'unknown_node';
        case 'addColumn': {
            const refusal = canAddColumn(definition, op.parentId);
            const count = columnCount(definition, op.parentId) ?? 0;
            return refusal ?? (op.index >= 0 && op.index <= count ? null : 'bad_index');
        }
        case 'removeColumn':
            return canRemoveColumn(definition, op.parentId, op.column);
    }
}
