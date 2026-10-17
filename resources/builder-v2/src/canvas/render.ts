/**
 * Keyed DOM renderer for the canvas (ADR 0037): one card per id, created once and patched in
 * place. Thanks to structural sharing an unchanged node object means an unchanged subtree, which
 * is skipped entirely. Livewire never morphs this DOM (it lives under `wire:ignore`).
 */
import { setAttrs } from '../dom.ts';
import type { Selection } from '../store/history.ts';
import {
    containerKey,
    indexTree,
    type Definition,
    type ListEntry,
    type TreeIndex,
} from '../store/tree.ts';
import {
    cardContainers,
    createCard,
    patchCard,
    setFlag,
    syncLists,
    type Card,
    type CardContext,
} from './card.ts';
import { nodeLabel } from './labels.ts';

export type Marker = 'error' | 'warning';

export interface ViewState {
    readonly selection: Selection;
    readonly lockedIds: ReadonlySet<string>;
    readonly markers: ReadonlyMap<string, Marker>;
    readonly collapsed: ReadonlySet<string>;
    readonly grabbedId: string | null;
}

export class CanvasRenderer {
    readonly #tree: HTMLElement;
    readonly #ctx: CardContext;
    readonly #cards = new Map<string, Card>();
    #active: string | null = null;

    /** `tree` is the page list (`role="tree"`, `data-builder-container="pages"`). */
    constructor(tree: HTMLElement, ctx: CardContext) {
        this.#tree = tree;
        this.#ctx = ctx;
    }

    render(definition: Definition, view: ViewState): void {
        const index = indexTree(definition);
        const pages = index.lists.get('pages');
        if (pages !== undefined) {
            this.#renderList(this.#tree, pages, index, view);
        }
        for (const [id, card] of [...this.#cards]) {
            const entry = index.nodes.get(id);
            if (entry === undefined) {
                this.#cards.delete(id);
            } else if (card.collapsed !== view.collapsed.has(id)) {
                // A skipped (unchanged) ancestor never reaches this card: apply collapse changes directly.
                card.collapsed = view.collapsed.has(id);
                patchCard(card, entry, card.collapsed, this.#ctx);
            }
        }
        this.decorate(view);
    }

    /**
     * Two phases: first every card of the list is brought up to date (recursively, which may move
     * nested cards around), then the list's own children are put in order.
     */
    #renderList(listEl: HTMLElement, list: ListEntry, index: TreeIndex, view: ViewState): void {
        const els = list.ids.map((id) => this.#ensureCard(id, index, view));
        let cursor = listEl.firstElementChild;
        els.forEach((el, position) => {
            setAttrs(el, {
                'aria-posinset': String(position + 1),
                'aria-setsize': String(els.length),
            });
            if (cursor === el) {
                cursor = cursor.nextElementSibling;
            } else {
                listEl.insertBefore(el, cursor);
            }
        });
        while (cursor !== null) {
            const next: Element | null = cursor.nextElementSibling;
            cursor.remove();
            cursor = next;
        }
    }

    #ensureCard(id: string, index: TreeIndex, view: ViewState): HTMLElement {
        const entry = index.nodes.get(id);
        if (entry === undefined) {
            throw new Error(`Cannot render unknown node ${id}`);
        }
        const collapsed = view.collapsed.has(id);
        let card = this.#cards.get(id);
        if (
            card?.node === entry.node &&
            card.depth === entry.depth &&
            card.collapsed === collapsed
        ) {
            return card.el;
        }
        if (card?.kind !== entry.kind) {
            card = createCard(entry.kind, id, this.#ctx);
            this.#cards.set(id, card);
        }
        patchCard(card, entry, collapsed, this.#ctx);
        const containers = cardContainers(entry);
        const lists = syncLists(card, containers, nodeLabel(entry.node, this.#ctx.tr), this.#ctx);
        containers.forEach((container, position) => {
            const list = index.lists.get(containerKey(container));
            const listEl = lists[position];
            if (list !== undefined && listEl !== undefined) {
                this.#renderList(listEl, list, index, view);
            }
        });
        card.node = entry.node;
        card.depth = entry.depth;
        card.collapsed = collapsed;
        return card.el;
    }

    /** Selection, locks, lint markers, the grabbed card and the roving tab stop. */
    decorate(view: ViewState): void {
        for (const card of this.#cards.values()) {
            const selected =
                view.selection !== null &&
                view.selection.kind === card.kind &&
                view.selection.id === card.id;
            const marker = view.markers.get(card.id) ?? null;
            const locked = card.kind === 'element' && view.lockedIds.has(card.id);
            setAttrs(card.el, {
                'aria-selected': String(selected),
                'data-builder-locked': locked,
                'data-builder-marker': marker,
                'data-builder-grabbed': view.grabbedId === card.id,
            });
            card.lock.hidden = !locked;
            setFlag(card, marker, this.#ctx.tr);
        }
        const fallback =
            view.selection !== null && view.selection.kind !== 'settings'
                ? view.selection.id
                : null;
        this.setActive(
            this.#active !== null && this.#cards.has(this.#active) ? this.#active : fallback,
        );
    }

    /** Makes one card (and its own buttons) the single tab stop of the tree. */
    setActive(id: string | null): void {
        const target = (id === null ? undefined : this.#cards.get(id)) ?? this.#firstCard();
        const previous = this.#active === null ? undefined : this.#cards.get(this.#active);
        if (previous !== undefined && previous !== target) {
            this.#tabStop(previous, false);
        }
        if (target !== undefined) {
            this.#tabStop(target, true);
        }
        this.#active = target?.id ?? null;
    }

    #tabStop(card: Card, on: boolean): void {
        const value = on ? '0' : '-1';
        for (const el of [card.el, card.handle, card.collapse, card.menu]) {
            setAttrs(el, { tabindex: value });
        }
    }

    #firstCard(): Card | undefined {
        const first = this.#tree.firstElementChild;
        const id = first instanceof HTMLElement ? first.dataset.builderPage : undefined;
        return id === undefined ? undefined : this.#cards.get(id);
    }

    get activeId(): string | null {
        return this.#active;
    }

    card(id: string): Card | null {
        return this.#cards.get(id) ?? null;
    }

    /** Every list element (the page list first), for SortableJS. */
    lists(): HTMLElement[] {
        return [
            this.#tree,
            ...Array.from(this.#tree.querySelectorAll<HTMLElement>('[data-builder-list]')),
        ];
    }

    /** Visible cards in document order (collapsed bodies skipped), for roving focus. */
    visibleCards(): HTMLElement[] {
        return Array.from(this.#tree.querySelectorAll<HTMLElement>('[role="treeitem"]')).filter(
            (el) => el.parentElement?.closest('[hidden]') === null,
        );
    }
}
