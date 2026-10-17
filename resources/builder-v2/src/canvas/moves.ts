/**
 * Keyboard / menu moves (ADR 0037) as pure functions: ↑/↓ within and across lists, → into the
 * previous container, ← out of the container, PageUp/PageDown to the neighbouring page. The result
 * is a `move` target; the nesting rules decide afterwards whether it is allowed.
 */
import type { Container } from '../contract.ts';
import {
    listEntry,
    nodeEntry,
    ownContainers,
    type Definition,
    type NodeEntry,
} from '../store/tree.ts';

export type MoveDirection = 'up' | 'down' | 'into' | 'out' | 'pagePrev' | 'pageNext';

export interface MoveTarget {
    readonly target: Container;
    readonly index: number;
}

/** Data attribute values of the menu items, by direction. */
export const MOVE_ACTIONS: Readonly<Record<MoveDirection, string>> = {
    up: 'move-up',
    down: 'move-down',
    into: 'move-into',
    out: 'move-out',
    pagePrev: 'move-page-prev',
    pageNext: 'move-page-next',
};

function sizeOf(definition: Definition, container: Container): number {
    return listEntry(definition, container)?.ids.length ?? 0;
}

function positionIn(definition: Definition, entry: NodeEntry): number {
    return listEntry(definition, entry.container)?.ids.indexOf(entry.id) ?? -1;
}

function pageIds(definition: Definition): readonly string[] {
    return listEntry(definition, { kind: 'pages' })?.ids ?? [];
}

/** Right after (or, with `before`, right before) the container element that owns the list. */
function besideOwner(definition: Definition, entry: NodeEntry, before: boolean): MoveTarget | null {
    if (entry.parentId === null) {
        return null;
    }
    const owner = nodeEntry(definition, entry.parentId);
    if (owner === null) {
        return null;
    }
    const position = positionIn(definition, owner);
    return { target: owner.container, index: before ? position : position + 1 };
}

function neighbourPage(definition: Definition, entry: NodeEntry, step: -1 | 1): MoveTarget | null {
    const pages = pageIds(definition);
    const pageId = pages[pages.indexOf(entry.pageId) + step];
    if (pageId === undefined) {
        return null;
    }
    const target: Container = { kind: 'page', pageId };
    return { target, index: step < 0 ? sizeOf(definition, target) : 0 };
}

function pageMove(
    definition: Definition,
    entry: NodeEntry,
    direction: MoveDirection,
): MoveTarget | null {
    const position = positionIn(definition, entry);
    const step =
        direction === 'up' || direction === 'pagePrev'
            ? -1
            : direction === 'down' || direction === 'pageNext'
              ? 1
              : 0;
    const index = position + step;
    return step === 0 || index < 0 || index >= pageIds(definition).length
        ? null
        : { target: { kind: 'pages' }, index };
}

function edgeMove(definition: Definition, entry: NodeEntry, step: -1 | 1): MoveTarget | null {
    const { container } = entry;
    if (container.kind === 'page') {
        return neighbourPage(definition, entry, step);
    }
    if (container.kind === 'column') {
        const owner = nodeEntry(definition, container.parentId);
        const columns = owner === null ? 0 : ownContainers(owner.node).length;
        const column = container.column + step;
        if (column >= 0 && column < columns) {
            const target: Container = { kind: 'column', parentId: container.parentId, column };
            return { target, index: step < 0 ? sizeOf(definition, target) : 0 };
        }
    }
    return besideOwner(definition, entry, step < 0);
}

function stepMove(definition: Definition, entry: NodeEntry, step: -1 | 1): MoveTarget | null {
    const position = positionIn(definition, entry);
    const size = sizeOf(definition, entry.container);
    const index = position + step;
    return index >= 0 && index < size
        ? { target: entry.container, index }
        : edgeMove(definition, entry, step);
}

function intoPrevious(definition: Definition, entry: NodeEntry): MoveTarget | null {
    const ids = listEntry(definition, entry.container)?.ids ?? [];
    const previousId = ids[ids.indexOf(entry.id) - 1];
    const previous = previousId === undefined ? null : nodeEntry(definition, previousId);
    const target = previous === null ? undefined : ownContainers(previous.node)[0];
    if (previous === null || target === undefined) {
        return null;
    }
    return { target, index: sizeOf(definition, target) };
}

export function computeMove(
    definition: Definition,
    id: string,
    direction: MoveDirection,
): MoveTarget | null {
    const entry = nodeEntry(definition, id);
    if (entry === null) {
        return null;
    }
    if (entry.kind === 'page') {
        return pageMove(definition, entry, direction);
    }
    switch (direction) {
        case 'up':
            return stepMove(definition, entry, -1);
        case 'down':
            return stepMove(definition, entry, 1);
        case 'into':
            return intoPrevious(definition, entry);
        case 'out':
            return besideOwner(definition, entry, false);
        case 'pagePrev':
            return neighbourPage(definition, entry, -1);
        case 'pageNext':
            return neighbourPage(definition, entry, 1);
    }
}
