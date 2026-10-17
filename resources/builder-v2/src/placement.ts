/** Where new nodes go (palette click/Enter) and the repeater confirmation rule for moves. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { Container } from './contract.ts';
import type { Selection } from './store/history.ts';
import { canDrop, type Refusal } from './store/rules.ts';
import {
    listEntry,
    nodeEntry,
    repeaterAncestors,
    subtreeIds,
    type Definition,
} from './store/tree.ts';

export interface Placement {
    readonly target: Container;
    readonly index: number;
}

function endOfPage(definition: Definition, pageId: string): Placement {
    const target: Container = { kind: 'page', pageId };
    return { target, index: listEntry(definition, target)?.ids.length ?? 0 };
}

/** After the selected element; else at the end of the selected page, else of the last page. */
export function insertionPoint(definition: Definition, selection: Selection): Placement | null {
    const entry =
        selection !== null && selection.kind !== 'settings'
            ? nodeEntry(definition, selection.id)
            : null;
    if (entry?.kind === 'element') {
        const position = listEntry(definition, entry.container)?.ids.indexOf(entry.id) ?? -1;
        return { target: entry.container, index: position + 1 };
    }
    const pageId =
        entry?.kind === 'page' ? entry.id : listEntry(definition, { kind: 'pages' })?.ids.at(-1);
    return pageId === undefined ? null : endOfPage(definition, pageId);
}

/** The requested place, or the end of the same page when the nesting rules refuse it. */
export function placeNewNode(
    definition: Definition,
    node: JsonObject,
    placement: Placement,
    maxContainerDepth: number,
): Placement | Refusal {
    const refusal = canDrop(
        definition,
        { node },
        placement.target,
        maxContainerDepth,
        placement.index,
    );
    if (refusal === null) {
        return placement;
    }
    const { target } = placement;
    const pageId =
        'parentId' in target ? nodeEntry(definition, target.parentId)?.pageId : undefined;
    if (pageId !== undefined) {
        const fallback = endOfPage(definition, pageId);
        if (
            canDrop(definition, { node }, fallback.target, maxContainerDepth, fallback.index) ===
            null
        ) {
            return fallback;
        }
    }
    return refusal;
}

/** A published element whose data path changes (into or out of a repeater) needs confirmation. */
export function needsRepeaterConfirm(
    before: Definition,
    after: Definition,
    id: string,
    locked: ReadonlySet<string>,
): boolean {
    const entry = nodeEntry(before, id);
    if (entry?.kind !== 'element' || !subtreeIds(entry.node).some((nodeId) => locked.has(nodeId))) {
        return false;
    }
    return repeaterAncestors(before, id).join('/') !== repeaterAncestors(after, id).join('/');
}
