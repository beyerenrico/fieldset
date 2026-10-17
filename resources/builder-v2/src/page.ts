/** Page-level bindings: settings and add-page buttons, the JSON dialog and the leave prompt. */
import type { Actions } from './actions.ts';
import type { BuilderStore } from './store/store.ts';

export interface PageBindings {
    readonly win: Window;
    readonly actions: Actions;
    readonly store: BuilderStore;
    readonly jsonDialog: HTMLDialogElement | null;
    readonly isDirty: () => boolean;
}

/** Settings and add-page buttons, the JSON dialog and the leave prompt; returns an unbinder. */
export function bindPage(root: HTMLElement, bindings: PageBindings): () => void {
    const { win, actions, store, jsonDialog } = bindings;
    const onBeforeUnload = (event: BeforeUnloadEvent): void => {
        if (bindings.isDirty()) {
            event.preventDefault();
        }
    };
    root.querySelector('[data-builder-settings]')?.addEventListener('click', () => {
        store.select({ kind: 'settings' });
    });
    root.querySelector('[data-builder-add-page]')?.addEventListener('click', () => {
        actions.addPage();
    });
    jsonDialog?.querySelector('[data-builder-json-close]')?.addEventListener('click', () => {
        jsonDialog.close();
    });
    win.addEventListener('beforeunload', onBeforeUnload);
    return () => {
        win.removeEventListener('beforeunload', onBeforeUnload);
    };
}
