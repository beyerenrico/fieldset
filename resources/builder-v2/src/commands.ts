/**
 * Toolbar commands. Publish, Preview PDF, export and switching to the classic builder first flush
 * pending inspector edits and autosaves; they run only when the server holds the local copy.
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

    /** Commits inspector edits and saves; announces why it could not. */
    async flush(): Promise<boolean> {
        await this.#deps.inspector.flush();
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

    async run(command: ToolbarCommand): Promise<void> {
        const { actions, scheduler, tr } = this.#deps;
        this.#deps.cancelGrab();
        const action = ACTIONS[command];
        if (action !== undefined) {
            if (await this.flush()) {
                await this.#deps.wire.mountAction(action, { checksum: scheduler.checksum });
            }
            return;
        }
        switch (command) {
            case 'undo':
                actions.undo();
                return;
            case 'redo':
                actions.redo();
                return;
            case 'save': {
                await this.#deps.inspector.flush();
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
