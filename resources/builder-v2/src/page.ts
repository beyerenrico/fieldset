/**
 * Page-level bindings: settings, add-page, "Go to properties" (button and Alt+P) and keyboard-help
 * buttons, the JSON dialog and the leave prompt.
 */
import type { Actions } from './actions.ts';
import { isEditableTarget, setAttrs } from './dom.ts';
import type { BuilderStore } from './store/store.ts';

export interface PageBindings {
    readonly win: Window;
    readonly actions: Actions;
    readonly store: BuilderStore;
    readonly jsonDialog: HTMLDialogElement | null;
    readonly isDirty: () => boolean;
    /** Moves focus to the first inspector field (announces when there is none). */
    readonly focusProperties: () => void;
}

const FIELD =
    'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])';

/** Focuses the first field of the inspector pane; false when it shows no field. */
export function focusFirstField(pane: ParentNode | null): boolean {
    const field = pane?.querySelector<HTMLElement>(FIELD) ?? null;
    field?.focus();
    return field !== null;
}

/** Alt+P (also on macOS, where the key reads "π"), never while typing. */
export function isPropertiesShortcut(event: KeyboardEvent): boolean {
    const p = event.code === 'KeyP' || event.key.toLowerCase() === 'p';
    return (
        p &&
        event.altKey &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.shiftKey &&
        !isEditableTarget(event.target)
    );
}

/** The keyboard help panel opens and closes with its toggle button. */
function bindHelp(root: HTMLElement): void {
    const toggle = root.querySelector<HTMLElement>('[data-builder-help-toggle]');
    const panel = root.querySelector<HTMLElement>('[data-builder-help]');
    toggle?.addEventListener('click', () => {
        if (panel !== null) {
            panel.hidden = !panel.hidden;
            setAttrs(toggle, { 'aria-expanded': String(!panel.hidden) });
        }
    });
}

/** Binds the page-level buttons, the Alt+P shortcut and the leave prompt; returns an unbinder. */
export function bindPage(root: HTMLElement, bindings: PageBindings): () => void {
    const { win, actions, store, jsonDialog } = bindings;
    const onBeforeUnload = (event: BeforeUnloadEvent): void => {
        if (bindings.isDirty()) {
            event.preventDefault();
        }
    };
    const onKeydown = (event: KeyboardEvent): void => {
        if (!event.defaultPrevented && isPropertiesShortcut(event)) {
            event.preventDefault();
            bindings.focusProperties();
        }
    };
    root.querySelector('[data-builder-settings]')?.addEventListener('click', () => {
        store.select({ kind: 'settings' });
    });
    root.querySelector('[data-builder-goto-inspector]')?.addEventListener('click', () => {
        bindings.focusProperties();
    });
    root.querySelector('[data-builder-add-page]')?.addEventListener('click', () => {
        actions.addPage();
    });
    bindHelp(root);
    jsonDialog?.querySelector('[data-builder-json-close]')?.addEventListener('click', () => {
        jsonDialog.close();
    });
    win.addEventListener('beforeunload', onBeforeUnload);
    win.document.addEventListener('keydown', onKeydown);
    return () => {
        win.removeEventListener('beforeunload', onBeforeUnload);
        win.document.removeEventListener('keydown', onKeydown);
    };
}
