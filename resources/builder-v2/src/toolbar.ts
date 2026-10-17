/**
 * Toolbar and shortcuts (ADR 0037): undo, redo, save, publish, Preview PDF, export, classic
 * builder, preview size and the read-only JSON view. Ctrl/Cmd+Z undoes, Ctrl/Cmd+Shift+Z or
 * Ctrl+Y redoes, Ctrl/Cmd+S saves; shortcuts are ignored inside text inputs.
 *
 * Unavailable commands are `aria-disabled`, never `disabled`: the button keeps focus and stays
 * discoverable, and a click explains itself (Commands announces why publishing is blocked).
 */
import { isEditableTarget, setAttrs, setText } from './dom.ts';

export const TOOLBAR_COMMANDS = [
    'undo',
    'redo',
    'save',
    'publish',
    'preview-pdf',
    'export',
    'classic',
    'preview-toggle',
    'json',
] as const;

export type ToolbarCommand = (typeof TOOLBAR_COMMANDS)[number];

export interface ToolbarFlags {
    readonly canUndo: boolean;
    readonly canRedo: boolean;
    /** Why publishing is blocked (lint errors, an unsaved invalid draft, a conflict), or null. */
    readonly publishBlocked: string | null;
    readonly tablet: boolean;
}

/** `aria-describedby` target of the Publish button: the reason it is unavailable. */
export const PUBLISH_REASON_ID = 'bv2-publish-reason';

function isCommand(value: string | undefined): value is ToolbarCommand {
    return (TOOLBAR_COMMANDS as readonly string[]).includes(value ?? '');
}

/** The shortcut a key event stands for, if any. */
export function shortcutOf(event: KeyboardEvent): 'undo' | 'redo' | 'save' | null {
    if (!(event.ctrlKey || event.metaKey) || event.altKey || isEditableTarget(event.target)) {
        return null;
    }
    const key = event.key.toLowerCase();
    if (key === 'z') {
        return event.shiftKey ? 'redo' : 'undo';
    }
    if (key === 'y' && event.ctrlKey && !event.shiftKey) {
        return 'redo';
    }
    return key === 's' && !event.shiftKey ? 'save' : null;
}

export class Toolbar {
    readonly #buttons = new Map<ToolbarCommand, HTMLButtonElement>();
    readonly #run: (command: ToolbarCommand) => void;
    readonly #doc: Document;

    constructor(
        root: HTMLElement,
        run: (command: ToolbarCommand) => void,
        doc: Document = document,
    ) {
        this.#run = run;
        this.#doc = doc;
        root.querySelectorAll<HTMLButtonElement>('[data-builder-toolbar]').forEach((button) => {
            const command = button.dataset.builderToolbar;
            if (isCommand(command)) {
                this.#buttons.set(command, button);
                button.addEventListener('click', () => {
                    run(command);
                });
            }
        });
        doc.addEventListener('keydown', this.#onKeydown);
    }

    destroy(): void {
        this.#doc.removeEventListener('keydown', this.#onKeydown);
    }

    readonly #onKeydown = (event: KeyboardEvent): void => {
        const command = event.defaultPrevented ? null : shortcutOf(event);
        if (command !== null) {
            event.preventDefault();
            this.#run(command);
        }
    };

    update(flags: ToolbarFlags): void {
        this.#toggle('undo', flags.canUndo);
        this.#toggle('redo', flags.canRedo);
        this.#toggle('publish', flags.publishBlocked === null);
        const publish = this.#buttons.get('publish');
        const reason = this.#doc.getElementById(PUBLISH_REASON_ID);
        if (publish !== undefined) {
            setAttrs(publish, {
                title: flags.publishBlocked,
                'aria-describedby': reason === null ? null : PUBLISH_REASON_ID,
            });
        }
        if (reason !== null) {
            setText(reason, flags.publishBlocked ?? '');
        }
        const preview = this.#buttons.get('preview-toggle');
        if (preview !== undefined) {
            setAttrs(preview, { 'aria-pressed': String(flags.tablet) });
        }
    }

    #toggle(command: ToolbarCommand, enabled: boolean): void {
        const button = this.#buttons.get(command);
        if (button !== undefined) {
            setAttrs(button, { 'aria-disabled': enabled ? null : 'true' });
        }
    }
}
