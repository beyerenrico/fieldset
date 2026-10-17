/** Builders for the canvas and sync halves of the app (each one small; app.ts connects them). */
import { Actions } from './actions.ts';
import { Announcer } from './canvas/announce.ts';
import { TypeIcons } from './canvas/icons.ts';
import { KeyboardController } from './canvas/keyboard.ts';
import { MoveMenu } from './canvas/menu.ts';
import { CanvasRenderer } from './canvas/render.ts';
import { SortableBinder } from './canvas/sortable.ts';
import type { BuilderConfig, BuilderWire, SyncResult } from './contract.ts';
import type { Translator } from './i18n.ts';
import { LintPanel, lintItems, markersOf, type LintItem } from './lint.ts';
import { Palette, paletteIcons, paletteTemplates } from './palette.ts';
import { PreviewFeed } from './preview.ts';
import { canDrop } from './store/rules.ts';
import type { BuilderStore } from './store/store.ts';
import type { Definition } from './store/tree.ts';
import { CanvasView } from './view.ts';

export interface Core {
    readonly root: HTMLElement;
    readonly win: Window;
    readonly wire: BuilderWire;
    readonly tr: Translator;
    readonly config: BuilderConfig;
    readonly store: BuilderStore;
    readonly announce: (message: string) => void;
    readonly confirm: (message: string) => boolean;
}

export interface CanvasParts {
    readonly actions: Actions;
    readonly view: CanvasView;
    readonly keyboard: KeyboardController;
    readonly destroy: () => void;
}

export function required(root: ParentNode, selector: string): HTMLElement {
    const el = root.querySelector<HTMLElement>(selector);
    if (el === null) {
        throw new Error(`Builder v2: ${selector} is missing`);
    }
    return el;
}

export function announcerFor(root: HTMLElement): (message: string) => void {
    const announcer = new Announcer(root.querySelector<HTMLElement>('[data-builder-live]'));
    return (message) => {
        announcer.announce(message);
    };
}

function createSortable(core: Core, actions: Actions, view: () => CanvasView): SortableBinder {
    const { store, config } = core;
    const templates = paletteTemplates(config.palette);
    return new SortableBinder({
        accepts: (subject, target) =>
            canDrop(store.state.definition, subject, target, config.maxContainerDepth) === null,
        paletteNode: (type) => {
            const node = templates.get(type);
            return node === undefined ? null : { node };
        },
        onDrop: (request) => {
            if (request.kind === 'insert') {
                actions.insertFromPalette(request.type, request);
            } else if (actions.moveTo(request.id, request.target, request.index)) {
                view().focus({ id: request.id, part: 'handle' });
            }
        },
        onDragChange: (dragging) => {
            view().setDragging(dragging);
        },
    });
}

function createKeyboard(
    core: Core,
    parts: { tree: HTMLElement; renderer: CanvasRenderer; actions: Actions; view: CanvasView },
): KeyboardController {
    const { store, tr, announce, confirm } = core;
    const { view } = parts;
    return new KeyboardController({
        ...parts,
        store,
        tr,
        announce,
        confirm,
        select: (selection) => {
            store.select(selection);
        },
        toggleCollapse: (id, collapsed) => {
            view.toggleCollapse(id, collapsed);
        },
        isCollapsed: (id) => view.isCollapsed(id),
        onGrab: (id) => {
            view.setGrabbed(id);
        },
        openMenu: (id) => {
            view.openMenu(id);
        },
    });
}

/** Store-driven canvas: renderer, drag and drop, keyboard, Move menu and the palette. */
export function createCanvas(core: Core): CanvasParts {
    const { root, store, tr, config } = core;
    const icons = new TypeIcons(
        root.querySelector('template[data-builder-icons]'),
        paletteIcons(config.palette),
    );
    const tree = required(root, '[data-builder-tree]');
    const renderer = new CanvasRenderer(tree, { tr, icons });
    const actions = new Actions({
        store,
        tr,
        announce: core.announce,
        confirm: core.confirm,
        cloneNode: (node, scopeKeys) => core.wire.cloneNode(node, scopeKeys),
        palette: paletteTemplates(config.palette),
        focus: (mark) => {
            view.focus(mark);
        },
    });
    const sortable = createSortable(core, actions, () => view);
    const menu = new MoveMenu({ tree, store, actions, tr });
    const view = new CanvasView({
        tree,
        store,
        renderer,
        sortable,
        menu,
        actions,
        settingsButton: root.querySelector('[data-builder-settings]'),
    });
    const keyboard = createKeyboard(core, { tree, renderer, actions, view });
    const palette = new Palette(required(root, '[data-builder-palette]'), config.palette, {
        tr,
        icons,
        insert: (type) => actions.insertFromPalette(type),
        justDragged: () => view.justDragged(),
    });
    sortable.bindPalette(palette.lists);
    const destroy = (): void => {
        [keyboard, palette, sortable, view].forEach((part) => {
            part.destroy();
        });
    };
    return { actions, view, keyboard, destroy };
}

export interface LintState {
    items: LintItem[];
}

/**
 * Applies a server answer to the lint panel and the card markers (a conflict carries no lint), and
 * announces once per answer when the number of problems changed.
 */
export function lintHandler(
    core: Core,
    view: CanvasView,
    state: LintState,
): (result: SyncResult, checked: Definition) => void {
    const { tr, announce } = core;
    const panel = new LintPanel(core.root.querySelector<HTMLElement>('[data-builder-lint]'), {
        tr,
        onPick: (selection) => {
            core.store.select(selection);
            if (selection !== null && selection.kind !== 'settings') {
                view.focus({ id: selection.id, part: 'card' });
            }
        },
    });
    let previous: number | null = null; // the first answer sets the baseline silently
    return (result, checked) => {
        if (result.status === 'conflict') {
            announce(tr.t('announce.conflict'));
            return;
        }
        state.items = lintItems(result.lint, checked);
        panel.update(state.items, checked);
        view.setMarkers(markersOf(state.items));
        const count = state.items.length;
        if (result.status === 'invalid') {
            announce(tr.choice('announce.invalid', result.lint.schema_errors.length));
        } else if (previous !== null && count !== previous) {
            announce(tr.choice('announce.lint_found', count));
        }
        previous = count;
    };
}

/** The preview lives in its own `wire:ignore` block after the inspector (focus order). */
export function createPreview(core: Core): PreviewFeed | null {
    const frame = core.win.document.querySelector<HTMLIFrameElement>(
        'iframe[data-builder-preview]',
    );
    return frame === null
        ? null
        : new PreviewFeed(frame, {
              getDefinition: () => core.store.state.definition,
              origin: new URL(core.config.urls.preview, core.win.location.href).origin,
              win: core.win,
          });
}
