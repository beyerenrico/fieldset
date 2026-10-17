/**
 * Toolbar commands. Publish, Preview PDF, export and switching to the classic builder first flush
 * pending inspector edits and autosaves; they run only when the server holds the local copy and the
 * inspector accepted its last edit. Undo/redo first wait for pending inspector edits, so an edit
 * becomes its own undo step instead of landing on top of the undone state.
 */
import type { Actions } from './actions.ts';
import type { BuilderWire } from './contract.ts';
import type { Translator } from './i18n.ts';
import type { InspectorGlue } from './inspector.ts';
import type { PreviewFeed } from './preview.ts';
import type { BuilderStore } from './store/store.ts';
import type { FlushOutcome, SyncScheduler } from './sync/scheduler.ts';
import type { ToolbarCommand } from './toolbar.ts';

export interface CommandDeps {
    readonly store: BuilderStore;
    readonly actions: Actions;
    readonly scheduler: SyncScheduler;
    readonly inspector: InspectorGlue;
    readonly wire: Pick<BuilderWire, 'mountAction'>;
    readonly preview: PreviewFeed | null;
    readonly tr: Translator;
    readonly announce: (message: string) => void;
    readonly classicUrl: string;
    readonly navigate: (url: string) => void;
    readonly jsonDialog: HTMLDialogElement | null;
    readonly cancelGrab: () => void;
    readonly onPreviewSize: (tablet: boolean) => void;
    /** Why publishing is blocked right now (lint errors, invalid draft, conflict), or null. */
    readonly publishBlocked: () => string | null;
}

const ACTIONS: Partial<Record<ToolbarCommand, 'publish' | 'previewPdf' | 'exportDraft'>> = {
    publish: 'publish',
    'preview-pdf': 'previewPdf',
    export: 'exportDraft',
};

export class Commands {
    readonly #deps: CommandDeps;
    #tablet = false;
    #leaving = false;

    constructor(deps: CommandDeps) {
        this.#deps = deps;
    }

    get tablet(): boolean {
        return this.#tablet;
    }

    /** True while the page navigates away on purpose (no leave prompt). */
    get leaving(): boolean {
        return this.#leaving;
    }

    /** Commits inspector edits (announces a refused edit); false when the inspector refused them. */
    async #commitInspector(): Promise<boolean> {
        if (await this.#deps.inspector.flush()) {
            return true;
        }
        this.#deps.announce(this.#deps.tr.t('announce.inspector_failed'));
        return false;
    }

    /** Commits inspector edits and saves; announces why it could not. */
    async flush(): Promise<boolean> {
        if (!(await this.#commitInspector())) {
            return false;
        }
        const outcome = await this.#deps.scheduler.flush();
        this.#reportFailure(outcome);
        return outcome === 'ok';
    }

    #reportFailure(outcome: FlushOutcome): void {
        if (outcome !== 'ok') {
            const { tr } = this.#deps;
            this.#deps.announce(tr.t('announce.refused', { reason: tr.t('refusal.unsaved') }));
        }
    }

    /**
     * Publishing is blocked by lint errors, an invalid draft or a conflict: the click announces why.
     * Unsaved edits are saved first, since they may be the fix.
     */
    async #publishAllowed(): Promise<boolean> {
        const { scheduler } = this.#deps;
        let blocked = this.#deps.publishBlocked();
        if (blocked !== null && scheduler.isDirty() && scheduler.state !== 'conflict') {
            if (!(await this.flush())) {
                return false;
            }
            blocked = this.#deps.publishBlocked();
        }
        if (blocked !== null) {
            this.#deps.announce(blocked);
        }
        return blocked === null;
    }

    async run(command: ToolbarCommand): Promise<void> {
        const { actions, scheduler, tr } = this.#deps;
        this.#deps.cancelGrab();
        const action = ACTIONS[command];
        if (command === 'publish' && !(await this.#publishAllowed())) {
            return;
        }
        if (action !== undefined) {
            if (await this.flush()) {
                await this.#deps.wire.mountAction(action, { checksum: scheduler.checksum });
            }
            return;
        }
        if ((command === 'undo' || command === 'redo') && this.#deps.inspector.busy) {
            await this.#deps.inspector.flush();
        }
        switch (command) {
            case 'undo':
                actions.undo();
                return;
            case 'redo':
                actions.redo();
                return;
            case 'save': {
                if (!(await this.#commitInspector())) {
                    return;
                }
                const outcome = await scheduler.save();
                if (outcome === 'ok') {
                    this.#deps.announce(tr.t('announce.saved'));
                }
                return;
            }
            case 'classic':
                if (await this.flush()) {
                    this.#leaving = true;
                    this.#deps.navigate(this.#deps.classicUrl);
                }
                return;
            case 'preview-toggle':
                this.#tablet = !this.#tablet;
                this.#deps.preview?.setSize(this.#tablet ? 'tablet' : 'phone');
                this.#deps.onPreviewSize(this.#tablet);
                return;
            default:
                this.#openJson();
        }
    }

    #openJson(): void {
        const dialog = this.#deps.jsonDialog;
        const content = dialog?.querySelector('[data-builder-json-content]');
        if (dialog === null || content === null || content === undefined) {
            return;
        }
        content.textContent = JSON.stringify(this.#deps.store.state.definition, null, 2);
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }
}
