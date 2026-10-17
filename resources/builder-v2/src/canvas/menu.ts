/**
 * The per-card "Move" menu (ADR 0037): the single-pointer alternative to dragging (WCAG 2.5.7).
 * Built lazily when opened, so 300 cards do not carry 2,400 hidden menu items.
 */
import type { Actions } from '../actions.ts';
import { h, setAttrs } from '../dom.ts';
import type { Translator } from '../i18n.ts';
import { checkOperation } from '../store/rules.ts';
import type { BuilderStore } from '../store/store.ts';
import { labelOf } from './labels.ts';
import { computeMove, MOVE_ACTIONS, type MoveDirection } from './moves.ts';
import { focusNode } from './focus.ts';

export interface MenuDeps {
    readonly tree: HTMLElement;
    readonly store: BuilderStore;
    readonly actions: Actions;
    readonly tr: Translator;
}

interface MenuItem {
    readonly action: string;
    readonly label: string;
    readonly enabled: boolean;
    readonly run: () => void;
}

const DIRECTIONS: readonly [MoveDirection, string][] = [
    ['up', 'menu.move_up'],
    ['down', 'menu.move_down'],
    ['into', 'menu.move_into'],
    ['out', 'menu.move_out'],
    ['pagePrev', 'menu.move_page_prev'],
    ['pageNext', 'menu.move_page_next'],
];

export class MoveMenu {
    readonly #deps: MenuDeps;
    #menu: HTMLElement | null = null;
    #button: HTMLElement | null = null;

    constructor(deps: MenuDeps) {
        this.#deps = deps;
    }

    get isOpen(): boolean {
        return this.#menu !== null;
    }

    #items(id: string): MenuItem[] {
        const { store, actions, tr } = this.#deps;
        const definition = store.state.definition;
        const moves = DIRECTIONS.map(([direction, key]): MenuItem => {
            const move = computeMove(definition, id, direction);
            const enabled =
                move !== null &&
                checkOperation(definition, { op: 'move', id, ...move }, store.maxContainerDepth) ===
                    null;
            return {
                action: MOVE_ACTIONS[direction],
                label: tr.t(key),
                enabled,
                run: () => {
                    actions.moveBy(id, direction);
                    focusNode(this.#deps.tree, { id, part: 'menu' });
                },
            };
        });
        return [
            ...moves,
            {
                action: 'duplicate',
                label: tr.t('menu.duplicate'),
                enabled: true,
                run: () => void actions.duplicate(id),
            },
            {
                action: 'delete',
                label: tr.t('menu.delete'),
                enabled: true,
                run: () => actions.remove(id),
            },
        ];
    }

    open(button: HTMLElement, id: string): void {
        this.close(false);
        const { tr, store } = this.#deps;
        const menu = h('div', {
            class: 'bv2-menu',
            role: 'menu',
            'aria-label': tr.t('canvas.actions', {
                label: labelOf(store.state.definition, id, tr),
            }),
        });
        for (const item of this.#items(id)) {
            const el = h(
                'button',
                {
                    type: 'button',
                    role: 'menuitem',
                    tabindex: '-1',
                    'data-builder-action': item.action,
                    'aria-disabled': item.enabled ? null : 'true',
                },
                [item.label],
            );
            el.addEventListener('click', () => {
                if (item.enabled) {
                    this.close(false);
                    item.run();
                }
            });
            menu.append(el);
        }
        menu.addEventListener('keydown', this.#onKeydown);
        button.after(menu);
        setAttrs(button, { 'aria-expanded': 'true' });
        this.#menu = menu;
        this.#button = button;
        document.addEventListener('pointerdown', this.#onOutside, true);
        this.#focusItem(0);
    }

    close(returnFocus = true): void {
        const button = this.#button;
        this.#menu?.remove();
        this.#menu = null;
        this.#button = null;
        document.removeEventListener('pointerdown', this.#onOutside, true);
        if (button !== null) {
            setAttrs(button, { 'aria-expanded': 'false' });
            if (returnFocus) {
                button.focus();
            }
        }
    }

    readonly #onOutside = (event: Event): void => {
        const target = event.target as Node | null;
        if (
            target !== null &&
            this.#menu?.contains(target) !== true &&
            this.#button?.contains(target) !== true
        ) {
            this.close(false);
        }
    };

    #itemsEls(): HTMLElement[] {
        return Array.from(this.#menu?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
    }

    #focusItem(index: number): void {
        const items = this.#itemsEls();
        const count = items.length;
        items[((index % count) + count) % count]?.focus();
    }

    readonly #onKeydown = (event: KeyboardEvent): void => {
        const items = this.#itemsEls();
        const current = items.indexOf(event.target as HTMLElement);
        const keys: Record<string, () => void> = {
            ArrowDown: () => {
                this.#focusItem(current + 1);
            },
            ArrowUp: () => {
                this.#focusItem(current - 1);
            },
            Home: () => {
                this.#focusItem(0);
            },
            End: () => {
                this.#focusItem(items.length - 1);
            },
            Escape: () => {
                this.close(true);
            },
            Tab: () => {
                this.close(true);
            },
        };
        const handler = keys[event.key];
        if (handler !== undefined) {
            event.preventDefault();
            event.stopPropagation();
            handler();
        }
    };
}
