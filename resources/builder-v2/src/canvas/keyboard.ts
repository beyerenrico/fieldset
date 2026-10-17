/**
 * Keyboard model of the canvas (ADR 0037). Navigation: roving focus between cards (↑/↓, Home/End,
 * →/← expand, collapse or go to child/parent), Enter/Space selects, Delete removes, Ctrl+D
 * duplicates. On a handle, Space/Enter picks the card up; then arrows move it, PageUp/PageDown
 * change the page, Space/Enter drops and Esc restores the original position.
 */
import type { Actions } from '../actions.ts';
import type { Translator } from '../i18n.ts';
import { labelOf } from './labels.ts';
import { needsRepeaterConfirm } from '../placement.ts';
import type { BuilderStore } from '../store/store.ts';
import type { Selection } from '../store/history.ts';
import { computeMove, type MoveDirection } from './moves.ts';
import { cardId, cardOf, focusNode } from './focus.ts';
import type { CanvasRenderer } from './render.ts';

export interface KeyboardDeps {
    readonly tree: HTMLElement;
    readonly renderer: CanvasRenderer;
    readonly store: BuilderStore;
    readonly actions: Actions;
    readonly tr: Translator;
    readonly announce: (message: string) => void;
    readonly confirm: (message: string) => boolean;
    readonly select: (selection: Selection) => void;
    readonly toggleCollapse: (id: string, collapsed?: boolean) => void;
    readonly isCollapsed: (id: string) => boolean;
    readonly onGrab: (id: string | null) => void;
}

const GRAB_KEYS: Readonly<Record<string, MoveDirection>> = {
    ArrowUp: 'up',
    ArrowDown: 'down',
    ArrowRight: 'into',
    ArrowLeft: 'out',
    PageUp: 'pagePrev',
    PageDown: 'pageNext',
};

export class KeyboardController {
    readonly #deps: KeyboardDeps;
    #grabbed: string | null = null;

    constructor(deps: KeyboardDeps) {
        this.#deps = deps;
        deps.tree.addEventListener('keydown', this.#onKeydown);
        document.addEventListener('pointerdown', this.#onPointerDown, true);
    }

    destroy(): void {
        this.#deps.tree.removeEventListener('keydown', this.#onKeydown);
        document.removeEventListener('pointerdown', this.#onPointerDown, true);
    }

    get grabbedId(): string | null {
        return this.#grabbed;
    }

    readonly #onPointerDown = (): void => {
        if (this.#grabbed !== null) {
            this.drop();
        }
    };

    readonly #onKeydown = (event: KeyboardEvent): void => {
        const card = cardOf(event.target as Element | null);
        const id = card === null ? null : cardId(card);
        if (card === null || id === null) {
            return;
        }
        const handled =
            this.#grabbed !== null ? this.#grabKey(event) : this.#navKey(event, card, id);
        if (handled) {
            event.preventDefault();
            event.stopPropagation();
        }
    };

    #grabKey(event: KeyboardEvent): boolean {
        const direction = GRAB_KEYS[event.key];
        if (direction !== undefined) {
            this.moveGrabbed(direction);
            return true;
        }
        if (event.key === ' ' || event.key === 'Enter') {
            this.drop();
            return true;
        }
        if (event.key === 'Escape') {
            this.cancel();
            return true;
        }
        if (event.key === 'Tab') {
            this.drop();
        }
        return false;
    }

    #navKey(event: KeyboardEvent, card: HTMLElement, id: string): boolean {
        const onHandle = (event.target as Element).matches('[data-builder-handle]');
        const onCard = event.target === card;
        const modifier = event.ctrlKey || event.metaKey;
        if (modifier && event.key.toLowerCase() === 'd') {
            void this.#deps.actions.duplicate(id);
            return true;
        }
        if (event.key === 'Delete' && (onCard || onHandle)) {
            this.#deps.actions.remove(id);
            return true;
        }
        if (onHandle && (event.key === ' ' || event.key === 'Enter')) {
            this.pickUp(id);
            return true;
        }
        return onCard && !modifier ? this.#cardKey(event.key, card, id) : false;
    }

    #cardKey(key: string, card: HTMLElement, id: string): boolean {
        switch (key) {
            case 'ArrowDown':
            case 'ArrowUp':
                return this.#focusSibling(card, key === 'ArrowDown' ? 1 : -1);
            case 'Home':
            case 'End':
                return this.#focusEdge(key === 'Home');
            case 'ArrowRight':
                return this.#expandOrEnter(card, id);
            case 'ArrowLeft':
                return this.#collapseOrLeave(card, id);
            case 'Enter':
            case ' ':
                this.#deps.select(
                    card.dataset.builderPage === undefined
                        ? { kind: 'element', id }
                        : { kind: 'page', id },
                );
                return true;
            default:
                return false;
        }
    }

    #focusCard(el: HTMLElement | undefined): boolean {
        const id = el === undefined ? null : cardId(el);
        if (el === undefined || id === null) {
            return false;
        }
        this.#deps.renderer.setActive(id);
        el.focus();
        return true;
    }

    #focusSibling(card: HTMLElement, step: 1 | -1): boolean {
        const cards = this.#deps.renderer.visibleCards();
        this.#focusCard(cards[cards.indexOf(card) + step]);
        return true;
    }

    #focusEdge(first: boolean): boolean {
        const cards = this.#deps.renderer.visibleCards();
        this.#focusCard(first ? cards[0] : cards.at(-1));
        return true;
    }

    #expandOrEnter(card: HTMLElement, id: string): boolean {
        if (!card.hasAttribute('aria-expanded')) {
            return false;
        }
        if (this.#deps.isCollapsed(id)) {
            this.#deps.toggleCollapse(id, false);
            return true;
        }
        const child = card.querySelector<HTMLElement>('[data-builder-list] > [role="treeitem"]');
        this.#focusCard(child ?? undefined);
        return true;
    }

    #collapseOrLeave(card: HTMLElement, id: string): boolean {
        if (card.getAttribute('aria-expanded') === 'true') {
            this.#deps.toggleCollapse(id, true);
            return true;
        }
        this.#focusCard(cardOf(card.parentElement) ?? undefined);
        return true;
    }

    pickUp(id: string): void {
        const { store, tr } = this.#deps;
        store.restore();
        this.#grabbed = id;
        this.#deps.onGrab(id);
        this.#deps.announce(
            tr.t('announce.picked_up', { label: labelOf(store.state.definition, id, tr) }),
        );
    }

    moveGrabbed(direction: MoveDirection): void {
        const id = this.#grabbed;
        if (id === null) {
            return;
        }
        const { store, actions } = this.#deps;
        const move = computeMove(store.state.definition, id, direction);
        if (move === null) {
            actions.refuse('edge');
            return;
        }
        const refusal = store.applyTransient({
            op: 'move',
            id,
            target: move.target,
            index: move.index,
        });
        if (refusal !== null) {
            actions.refuse(refusal);
            return;
        }
        focusNode(this.#deps.tree, { id, part: 'handle' });
        actions.announcePosition(id);
    }

    drop(): void {
        const id = this.#grabbed;
        if (id === null) {
            return;
        }
        const { store, tr } = this.#deps;
        const before = store.state.history.present.definition;
        const label = labelOf(store.state.definition, id, tr);
        this.#release();
        if (before === store.state.definition) {
            this.#deps.announce(tr.t('announce.dropped', { label }));
            return;
        }
        if (
            needsRepeaterConfirm(before, store.state.definition, id, store.state.lockedIds) &&
            !this.#deps.confirm(tr.t('confirm.repeater', { label }))
        ) {
            store.restore();
            this.#deps.actions.refuse('declined');
        } else {
            store.commit(tr.t('history.move', { label }));
            this.#deps.announce(tr.t('announce.dropped', { label }));
        }
        focusNode(this.#deps.tree, { id, part: 'handle' });
    }

    cancel(): void {
        const id = this.#grabbed;
        if (id === null) {
            return;
        }
        const { store, tr } = this.#deps;
        this.#release();
        store.restore();
        this.#deps.announce(
            tr.t('announce.cancelled', { label: labelOf(store.state.definition, id, tr) }),
        );
        focusNode(this.#deps.tree, { id, part: 'handle' });
    }

    #release(): void {
        this.#grabbed = null;
        this.#deps.onGrab(null);
    }
}
