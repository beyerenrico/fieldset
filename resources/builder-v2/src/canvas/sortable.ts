/**
 * SortableJS binding (ADR 0037): our own import, forceFallback, handles, groups (pages vs
 * elements, the palette as clone source). SortableJS only proposes a drop: in onEnd its DOM move
 * is reverted (or the palette clone put back) and the store applies the operation; the canvas
 * then re-renders and the instances are recreated.
 */
import Sortable from 'sortablejs';
import type { Container } from '../contract.ts';
import { parseContainerKey } from '../store/tree.ts';
import type { DropSubject } from '../store/rules.ts';

export type DropRequest =
    | {
          readonly kind: 'move';
          readonly id: string;
          readonly target: Container;
          readonly index: number;
      }
    | {
          readonly kind: 'insert';
          readonly type: string;
          readonly target: Container;
          readonly index: number;
      };

export interface SortableDeps {
    /** Whether the dragged element may go into the list (nesting rules). */
    readonly accepts: (subject: DropSubject, target: Container) => boolean;
    readonly paletteNode: (type: string) => DropSubject | null;
    readonly onDrop: (request: DropRequest) => void;
    readonly onDragChange: (dragging: boolean) => void;
}

const reducedMotion =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const COMMON: Sortable.Options = {
    forceFallback: true,
    fallbackOnBody: true,
    fallbackTolerance: 3,
    emptyInsertThreshold: 16,
    swapThreshold: 0.65,
    animation: reducedMotion ? 0 : 120,
    ghostClass: 'bv2-ghost',
    chosenClass: 'bv2-chosen',
    dragClass: 'bv2-drag',
    fallbackClass: 'bv2-fallback',
};

function containerOf(el: HTMLElement): Container | null {
    return parseContainerKey(el.dataset.builderContainer ?? '');
}

export function subjectOf(
    item: HTMLElement,
    paletteNode: SortableDeps['paletteNode'],
): DropSubject | null {
    const id = item.dataset.builderNode ?? item.dataset.builderPage;
    if (id !== undefined) {
        return { id };
    }
    const type = item.dataset.builderPaletteItem;
    return type === undefined ? null : paletteNode(type);
}

/** Puts SortableJS's DOM change back, so only the renderer changes the canvas. */
export function revertDom(
    event: Pick<Sortable.SortableEvent, 'item' | 'from' | 'to' | 'oldIndex' | 'clone' | 'pullMode'>,
): void {
    const { item, from, oldIndex, clone, pullMode } = event;
    if (pullMode === 'clone') {
        clone.replaceWith(item);
        return;
    }
    item.remove();
    from.insertBefore(item, from.children[oldIndex ?? from.children.length] ?? null);
}

const CARD = '[data-builder-node], [data-builder-page]';

/** The cards before the dropped item: its final index once the drop is applied. */
function cardsBefore(item: HTMLElement): number {
    let count = 0;
    for (let el = item.previousElementSibling; el !== null; el = el.previousElementSibling) {
        if (el.matches(CARD)) {
            count += 1;
        }
    }
    return count;
}

/**
 * Reads the proposed drop (final index in the target list) from an onEnd event. The index comes
 * from the item's place in the target list: SortableJS's newDraggableIndex counts with the source
 * list's draggable selector, which for a palette clone matches no card and is always 0.
 */
export function dropRequest(
    event: Pick<
        Sortable.SortableEvent,
        'item' | 'from' | 'to' | 'newDraggableIndex' | 'oldDraggableIndex'
    >,
): DropRequest | null {
    const target = containerOf(event.to);
    const index =
        event.item.parentElement === event.to ? cardsBefore(event.item) : event.newDraggableIndex;
    if (target === null || index === undefined) {
        return null;
    }
    const id = event.item.dataset.builderNode ?? event.item.dataset.builderPage;
    if (id !== undefined) {
        const unchanged = event.from === event.to && event.oldDraggableIndex === index;
        return unchanged ? null : { kind: 'move', id, target, index };
    }
    const type = event.item.dataset.builderPaletteItem;
    return type === undefined || event.to.closest('[data-builder-palette]') !== null
        ? null
        : { kind: 'insert', type, target, index };
}

export class SortableBinder {
    readonly #deps: SortableDeps;
    #lists: Sortable[] = [];
    #palette: Sortable[] = [];

    constructor(deps: SortableDeps) {
        this.#deps = deps;
    }

    #onEnd = (event: Sortable.SortableEvent): void => {
        const request = dropRequest(event);
        revertDom(event);
        this.#deps.onDragChange(false);
        // SortableJS still cleans up after onEnd; apply the drop once it is done.
        queueMicrotask(() => {
            if (request !== null) {
                this.#deps.onDrop(request);
            }
        });
    };

    #onStart = (): void => {
        this.#deps.onDragChange(true);
    };

    #put = (to: Sortable, _from: Sortable, dragEl: HTMLElement): boolean => {
        const target = containerOf(to.el);
        const subject = subjectOf(dragEl, this.#deps.paletteNode);
        return target !== null && subject !== null && this.#deps.accepts(subject, target);
    };

    /** Recreates the instances for the current lists (call after every structural render). */
    bind(lists: readonly HTMLElement[]): void {
        this.#lists.forEach((sortable) => {
            sortable.destroy();
        });
        this.#lists = lists.map((el) => {
            const pages = el.dataset.builderContainer === 'pages';
            return Sortable.create(el, {
                ...COMMON,
                group: pages
                    ? { name: 'pages', pull: false, put: false }
                    : { name: 'elements', pull: true, put: this.#put },
                handle: pages ? '[data-builder-handle="page"]' : '[data-builder-handle="element"]',
                draggable: pages ? '[data-builder-page]' : '[data-builder-node]',
                onStart: this.#onStart,
                onEnd: this.#onEnd,
            });
        });
    }

    /** Palette lists are clone sources: they never accept drops and never reorder. */
    bindPalette(lists: readonly HTMLElement[]): void {
        this.#palette = lists.map((el) =>
            Sortable.create(el, {
                ...COMMON,
                group: { name: 'elements', pull: 'clone', put: false },
                sort: false,
                draggable: '[data-builder-palette-item]',
                onStart: this.#onStart,
                onEnd: this.#onEnd,
            }),
        );
    }

    get instanceCount(): number {
        return this.#lists.length + this.#palette.length;
    }

    destroy(): void {
        [...this.#lists, ...this.#palette].forEach((sortable) => {
            sortable.destroy();
        });
        this.#lists = [];
        this.#palette = [];
    }
}
