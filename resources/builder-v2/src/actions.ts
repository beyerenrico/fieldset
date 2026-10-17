/**
 * User-level commands shared by drag and drop, the keyboard, the Move menu, the palette and the
 * toolbar: validate, confirm, dispatch, announce and move focus.
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { Container } from './contract.ts';
import { computeMove, type MoveDirection } from './canvas/moves.ts';
import { labelOf, listLabel, nodeLabel, positionOf } from './canvas/labels.ts';
import type { FocusMark } from './canvas/focus.ts';
import type { Translator } from './i18n.ts';
import { uuidv7, withFreshIds } from './store/ids.ts';
import { isObject, str } from './store/json.ts';
import { applyOperation } from './store/ops.ts';
import { checkOperation, type Refusal } from './store/rules.ts';
import { insertionPoint, needsRepeaterConfirm, placeNewNode, type Placement } from './placement.ts';
import { keysInScopeOf, uniqueKey } from './store/scope.ts';
import type { BuilderStore } from './store/store.ts';
import { listEntry, nodeEntry, type Definition } from './store/tree.ts';

export type Reason = Refusal | 'edge' | 'declined' | 'unsaved';

export interface ActionDeps {
    readonly store: BuilderStore;
    readonly tr: Translator;
    readonly announce: (message: string) => void;
    readonly confirm: (message: string) => boolean;
    readonly cloneNode: (node: string, scopeKeys: string) => Promise<string>;
    /** Palette templates by element type (without ids). */
    readonly palette: ReadonlyMap<string, JsonObject>;
    readonly focus: (mark: FocusMark) => void;
}

export class Actions {
    readonly #deps: ActionDeps;

    constructor(deps: ActionDeps) {
        this.#deps = deps;
    }

    get #definition(): Definition {
        return this.#deps.store.state.definition;
    }

    refuse(reason: Reason): void {
        const { tr } = this.#deps;
        const text = tr.t(`refusal.${reason}`, { max: this.#deps.store.maxContainerDepth });
        this.#deps.announce(tr.t('announce.refused', { reason: text }));
    }

    insertFromPalette(type: string, placement?: Placement): boolean {
        const { store } = this.#deps;
        const template = this.#deps.palette.get(type);
        const requested = placement ?? insertionPoint(this.#definition, store.state.selection);
        if (template === undefined || requested === null) {
            this.refuse('unknown_target');
            return false;
        }
        const node = withFreshIds(template);
        const place = placeNewNode(this.#definition, node, requested, store.maxContainerDepth);
        if (typeof place === 'string') {
            this.refuse(place);
            return false;
        }
        return this.insert(node, place, true);
    }

    /** Inserts a node with a key made unique in the target scope; selects and focuses it. */
    insert(
        node: JsonObject,
        placement: Placement,
        autoKey: boolean,
        historyKey = 'history.insert',
    ): boolean {
        const { store, tr } = this.#deps;
        const key = str(node.key);
        const unique =
            key === null
                ? node
                : {
                      ...node,
                      key: uniqueKey(key, keysInScopeOf(this.#definition, placement.target)),
                  };
        const id = str(unique.id) ?? '';
        const label = nodeLabel(unique, tr);
        const refusal = store.dispatch(
            { op: 'insert', target: placement.target, index: placement.index, node: unique },
            {
                label: tr.t(historyKey, { label }),
                select: { kind: placement.target.kind === 'pages' ? 'page' : 'element', id },
            },
        );
        if (refusal !== null) {
            this.refuse(refusal);
            return false;
        }
        store.setAutoKey(id, autoKey);
        this.#deps.announce(
            tr.t(historyKey === 'history.duplicate' ? 'announce.duplicated' : 'announce.inserted', {
                label,
            }),
        );
        this.#deps.focus({ id, part: 'card' });
        return true;
    }

    /** Moves a node (drag and drop, menu, keyboard drop) after the repeater confirmation. */
    moveTo(id: string, target: Container, index: number): boolean {
        const { store, tr } = this.#deps;
        const before = this.#definition;
        const op = { op: 'move', id, target, index } as const;
        const refusal = checkOperation(before, op, store.maxContainerDepth);
        if (refusal !== null) {
            this.refuse(refusal);
            return false;
        }
        const label = labelOf(before, id, tr);
        if (
            needsRepeaterConfirm(before, applyOperation(before, op), id, store.state.lockedIds) &&
            !this.#deps.confirm(tr.t('confirm.repeater', { label }))
        ) {
            this.refuse('declined');
            return false;
        }
        store.dispatch(op, { label: tr.t('history.move', { label }) });
        this.announcePosition(id);
        return true;
    }

    moveBy(id: string, direction: MoveDirection): boolean {
        const move = computeMove(this.#definition, id, direction);
        if (move === null) {
            this.refuse('edge');
            return false;
        }
        return this.moveTo(id, move.target, move.index);
    }

    announcePosition(id: string): void {
        const { tr } = this.#deps;
        const definition = this.#definition;
        const entry = nodeEntry(definition, id);
        const place = positionOf(definition, id);
        if (entry !== null && place !== null) {
            const list = listLabel(definition, entry.container, tr);
            this.#deps.announce(
                tr.t('announce.moved', { label: labelOf(definition, id, tr), ...place, list }),
            );
        }
    }

    remove(id: string): boolean {
        const { store, tr } = this.#deps;
        const definition = this.#definition;
        const entry = nodeEntry(definition, id);
        if (entry === null) {
            this.refuse('unknown_node');
            return false;
        }
        const ids = listEntry(definition, entry.container)?.ids ?? [];
        const position = ids.indexOf(id);
        const next =
            ids[position + 1] ??
            ids[position - 1] ??
            entry.parentId ??
            (entry.kind === 'element' ? entry.pageId : null);
        const label = nodeLabel(entry.node, tr);
        store.dispatch({ op: 'remove', id }, { label: tr.t('history.delete', { label }) });
        this.#deps.announce(tr.t('announce.deleted', { label }));
        if (next !== null) {
            this.#deps.focus({ id: next, part: 'card' });
        }
        return true;
    }

    /** Duplicates a page or element through the server (fresh ids, unique keys, rewritten references). */
    async duplicate(id: string): Promise<boolean> {
        const definition = this.#definition;
        const entry = nodeEntry(definition, id);
        if (entry === null) {
            this.refuse('unknown_node');
            return false;
        }
        const keys =
            entry.kind === 'page'
                ? [
                      ...keysInScopeOf(definition, { kind: 'pages' }),
                      ...keysInScopeOf(definition, { kind: 'page', pageId: id }),
                  ]
                : [...keysInScopeOf(definition, entry.container)];
        let clone: unknown;
        try {
            clone = JSON.parse(
                await this.#deps.cloneNode(JSON.stringify(entry.node), JSON.stringify(keys)),
            );
        } catch {
            this.refuse('unsaved');
            return false;
        }
        const current = nodeEntry(this.#definition, id);
        if (!isObject(clone) || str(clone.id) === null || current === null) {
            this.refuse('unknown_node');
            return false;
        }
        const position = listEntry(this.#definition, current.container)?.ids.indexOf(id) ?? -1;
        return this.insert(
            clone,
            { target: current.container, index: position + 1 },
            false,
            'history.duplicate',
        );
    }

    addColumn(parentId: string): boolean {
        const { store, tr } = this.#deps;
        const columns = nodeEntry(this.#definition, parentId)?.node.columns;
        const index = Array.isArray(columns) ? columns.length : 0;
        const label = labelOf(this.#definition, parentId, tr);
        const refusal = store.dispatch(
            { op: 'addColumn', parentId, index },
            { label: tr.t('history.add_column', { label }) },
        );
        this.#report(refusal, 'announce.column_added');
        return refusal === null;
    }

    removeColumn(parentId: string, column: number): boolean {
        const { store, tr } = this.#deps;
        const label = labelOf(this.#definition, parentId, tr);
        const refusal = store.dispatch(
            { op: 'removeColumn', parentId, column },
            { label: tr.t('history.remove_column', { label }) },
        );
        this.#report(refusal, 'announce.column_removed');
        return refusal === null;
    }

    #report(refusal: Refusal | null, success: string): void {
        if (refusal === null) {
            this.#deps.announce(this.#deps.tr.t(success));
        } else {
            this.refuse(refusal);
        }
    }

    addPage(): boolean {
        const { tr } = this.#deps;
        const pages = listEntry(this.#definition, { kind: 'pages' })?.ids ?? [];
        const number = pages.length + 1;
        const key = uniqueKey(
            `page_${String(number)}`,
            keysInScopeOf(this.#definition, { kind: 'pages' }),
        );
        const title = tr.t('canvas.page_title', { number });
        const node: JsonObject = { id: uuidv7(), key, title, elements: [] };
        const ok = this.insert(
            node,
            { target: { kind: 'pages' }, index: pages.length },
            false,
            'history.add_page',
        );
        if (ok) {
            // insert() already selected the page (and the inspector follows the selection).
            this.#deps.announce(tr.t('announce.page_added', { label: title }));
        }
        return ok;
    }

    undo(): void {
        const label = this.#deps.store.undo();
        this.#deps.announce(
            this.#deps.tr.t(label === null ? 'announce.nothing_to_undo' : 'announce.undone', {
                label: label ?? '',
            }),
        );
    }

    redo(): void {
        const label = this.#deps.store.redo();
        this.#deps.announce(
            this.#deps.tr.t(label === null ? 'announce.nothing_to_redo' : 'announce.redone', {
                label: label ?? '',
            }),
        );
    }
}
