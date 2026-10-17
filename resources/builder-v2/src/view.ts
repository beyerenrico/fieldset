/**
 * The canvas view: renders the store into the tree, keeps view-only state (collapsed containers,
 * lint markers, the grabbed card), restores focus by id after a render, recreates the SortableJS
 * instances and routes clicks on cards (select, collapse, Move menu, columns).
 */
import type { Actions } from './actions.ts';
import { DragClickGuard } from './canvas/click-guard.ts';
import { cardId, cardOf, focusMark, focusNode, type FocusMark } from './canvas/focus.ts';
import type { MoveMenu } from './canvas/menu.ts';
import type { CanvasRenderer, Marker, ViewState } from './canvas/render.ts';
import type { SortableBinder } from './canvas/sortable.ts';
import type { BuilderStore, StoreState } from './store/store.ts';

export interface ViewDeps {
    readonly tree: HTMLElement;
    readonly store: BuilderStore;
    readonly renderer: CanvasRenderer;
    readonly sortable: SortableBinder;
    readonly menu: MoveMenu;
    readonly actions: Actions;
    readonly settingsButton: HTMLElement | null;
}

export class CanvasView {
    readonly #deps: ViewDeps;
    #collapsed: ReadonlySet<string> = new Set();
    #markers: ReadonlyMap<string, Marker> = new Map();
    #grabbed: string | null = null;
    #dragging = false;
    #pending = false;
    #renderedDefinition: object | null = null;
    readonly #clickGuard: DragClickGuard;

    constructor(deps: ViewDeps) {
        this.#deps = deps;
        this.#clickGuard = new DragClickGuard(deps.tree.ownerDocument);
        deps.tree.addEventListener('click', this.#onClick);
        deps.tree.addEventListener('focusin', this.#onFocusIn);
    }

    destroy(): void {
        this.#deps.tree.removeEventListener('click', this.#onClick);
        this.#deps.tree.removeEventListener('focusin', this.#onFocusIn);
        this.#clickGuard.destroy();
    }

    #viewState(state: StoreState): ViewState {
        return {
            selection: state.selection,
            lockedIds: state.lockedIds,
            markers: this.#markers,
            collapsed: this.#collapsed,
            grabbedId: this.#grabbed,
        };
    }

    /** Renders now, or right after the running drag ends. */
    render(state: StoreState = this.#deps.store.state): void {
        if (this.#dragging) {
            this.#pending = true;
            return;
        }
        const { renderer, sortable, tree, settingsButton } = this.#deps;
        const mark = focusMark(tree);
        const structural = this.#renderedDefinition !== state.definition || this.#pending;
        renderer.render(state.definition, this.#viewState(state));
        if (structural) {
            sortable.bind(renderer.lists());
        }
        this.#renderedDefinition = state.definition;
        this.#pending = false;
        settingsButton?.setAttribute('aria-pressed', String(state.selection?.kind === 'settings'));
        this.#restoreFocus(mark);
    }

    #restoreFocus(mark: FocusMark | null): void {
        if (mark !== null && !this.#deps.tree.contains(document.activeElement)) {
            focusNode(this.#deps.tree, mark);
        }
    }

    /** Redraws markers, selection and locks without touching the structure. */
    decorate(): void {
        this.#deps.renderer.decorate(this.#viewState(this.#deps.store.state));
    }

    focus(mark: FocusMark): void {
        this.#deps.renderer.setActive(mark.id);
        focusNode(this.#deps.tree, mark);
    }

    setMarkers(markers: ReadonlyMap<string, Marker>): void {
        this.#markers = markers;
        this.decorate();
    }

    setGrabbed(id: string | null): void {
        this.#grabbed = id;
        this.decorate();
    }

    isCollapsed(id: string): boolean {
        return this.#collapsed.has(id);
    }

    toggleCollapse(id: string, collapsed: boolean = !this.#collapsed.has(id)): void {
        const next = new Set(this.#collapsed);
        if (collapsed) {
            next.add(id);
        } else {
            next.delete(id);
        }
        this.#collapsed = next;
        this.#renderedDefinition = null;
        this.render();
    }

    setDragging(dragging: boolean): void {
        this.#dragging = dragging;
        if (!dragging) {
            this.#clickGuard.arm();
            if (this.#pending) {
                this.render();
            }
        }
    }

    /** True, once, for the click a drag gesture ends with (it must not select or insert again). */
    justDragged(): boolean {
        return this.#clickGuard.consume();
    }

    readonly #onFocusIn = (event: FocusEvent): void => {
        const card = cardOf(event.target as Element | null);
        const id = card === null ? null : cardId(card);
        if (id !== null && id !== this.#deps.renderer.activeId) {
            this.#deps.renderer.setActive(id);
        }
    };

    readonly #onClick = (event: MouseEvent): void => {
        const target = event.target as Element | null;
        const card = cardOf(target);
        const id = card === null ? null : cardId(card);
        if (target === null || card === null || id === null || this.justDragged()) {
            return;
        }
        const button = target.closest<HTMLElement>('button');
        if (button !== null && card.contains(button)) {
            this.#onButton(button, card, id);
            return;
        }
        this.#deps.store.select(
            card.dataset.builderPage === undefined ? { kind: 'element', id } : { kind: 'page', id },
        );
    };

    #onButton(button: HTMLElement, card: HTMLElement, id: string): void {
        const { actions, menu } = this.#deps;
        const owner = button.dataset.builderOwner;
        if (button.hasAttribute('data-builder-collapse')) {
            this.toggleCollapse(id);
            focusNode(this.#deps.tree, { id, part: 'collapse' });
        } else if (button.hasAttribute('data-builder-menu')) {
            if (menu.isOpen && button.getAttribute('aria-expanded') === 'true') {
                menu.close();
            } else {
                menu.open(button, id);
            }
        } else if (button.dataset.builderAction === 'add-column' && owner !== undefined) {
            actions.addColumn(owner);
        } else if (button.dataset.builderAction === 'remove-column' && owner !== undefined) {
            actions.removeColumn(owner, Number(button.dataset.column));
        } else if (button.hasAttribute('data-builder-handle')) {
            this.#deps.store.select(
                card.dataset.builderPage === undefined
                    ? { kind: 'element', id }
                    : { kind: 'page', id },
            );
        }
    }
}
