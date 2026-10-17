/** Mounts Builder v2 on its root: store, canvas, sync, preview, lint, inspector and toolbar. */
import { Commands } from './commands.ts';
import { readConfig } from './config.ts';
import type { BuilderWire } from './contract.ts';
import type { PreviewFeed } from './preview.ts';
import { toStrings, Translator } from './i18n.ts';
import { InspectorGlue } from './inspector.ts';
import { hasErrors } from './lint.ts';
import { bindPage, focusFirstField } from './page.ts';
import { SyncStatus } from './status.ts';
import { canRedo, canUndo } from './store/history.ts';
import { BuilderStore } from './store/store.ts';
import { SyncScheduler } from './sync/scheduler.ts';
import { Toolbar } from './toolbar.ts';
import {
    announcerFor,
    createCanvas,
    createPreview,
    lintHandler,
    type CanvasParts,
    type Core,
    type LintState,
} from './wiring.ts';

export interface MountOptions {
    readonly wire: BuilderWire;
    readonly strings: unknown;
    readonly confirm?: (message: string) => boolean;
    readonly navigate?: (url: string) => void;
    readonly reload?: () => void;
    /** The page (for the inspector pane and the leave prompt); defaults to the global one. */
    readonly win?: Window;
}

export interface BuilderApp {
    readonly store: BuilderStore;
    readonly scheduler: SyncScheduler;
    readonly commands: Commands;
    destroy(): void;
}

function createCore(root: HTMLElement, options: MountOptions): Core {
    const win = options.win ?? window;
    const config = readConfig(root);
    return {
        root,
        win,
        wire: options.wire,
        tr: new Translator(toStrings(options.strings)),
        config,
        store: new BuilderStore(config.definition, {
            maxContainerDepth: config.maxContainerDepth,
            lockedIds: config.lockedIds,
        }),
        announce: announcerFor(root),
        confirm: options.confirm ?? ((message) => win.confirm(message)),
    };
}

interface Hooks {
    updateToolbar: () => void;
    leaving: boolean;
}

function createScheduler(
    core: Core,
    canvas: CanvasParts,
    lint: LintState,
    hooks: Hooks,
    options: MountOptions,
): SyncScheduler {
    const onResult = lintHandler(core, canvas.view, lint);
    // Both ways out of a conflict lose someone's changes: each one is confirmed first.
    const status = new SyncStatus(core.root, core.tr, {
        reload: () => {
            if (!core.confirm(core.tr.t('conflict.confirm_reload'))) {
                return;
            }
            hooks.leaving = true;
            if (options.reload === undefined) {
                core.win.location.reload();
            } else {
                options.reload();
            }
        },
        overwrite: () => {
            if (core.confirm(core.tr.t('conflict.confirm_overwrite'))) {
                void scheduler.overwrite();
            }
        },
    });
    const scheduler = new SyncScheduler(
        {
            wire: core.wire,
            getDefinition: () => core.store.state.definition,
            onState: (state) => {
                status.set(state);
                hooks.updateToolbar();
            },
            onResult: (result, checked) => {
                onResult(result, checked);
                hooks.updateToolbar();
            },
            onError: () => {
                core.announce(core.tr.t('announce.save_failed'));
            },
        },
        core.config.checksum,
        core.store.state.definition,
    );
    return scheduler;
}

function createCommands(
    core: Core,
    parts: {
        canvas: CanvasParts;
        scheduler: SyncScheduler;
        inspector: InspectorGlue;
        hooks: Hooks;
        preview: PreviewFeed | null;
        lint: LintState;
    },
    options: MountOptions,
): Commands {
    const { root, win } = core;
    return new Commands({
        store: core.store,
        actions: parts.canvas.actions,
        scheduler: parts.scheduler,
        inspector: parts.inspector,
        wire: core.wire,
        preview: parts.preview,
        tr: core.tr,
        announce: core.announce,
        classicUrl: core.config.urls.classic,
        navigate:
            options.navigate ??
            ((url) => {
                win.location.assign(url);
            }),
        jsonDialog: root.querySelector<HTMLDialogElement>('dialog[data-builder-json]'),
        cancelGrab: () => {
            parts.canvas.keyboard.cancel();
        },
        onPreviewSize: (tablet) => {
            const size = tablet ? 'tablet' : 'phone';
            root.dataset.builderPreviewSize = size;
            const frame = win.document.querySelector('iframe[data-builder-preview]');
            frame?.setAttribute('data-size', size);
            frame?.closest('[data-builder-preview-pane]')?.setAttribute('data-size', size);
            parts.hooks.updateToolbar();
        },
        publishBlocked: () => publishBlock(core, parts.scheduler, parts.lint),
    });
}

/** Why publishing is blocked: a conflict, lint errors or an invalid (unsaved) draft. */
function publishBlock(core: Core, scheduler: SyncScheduler, lint: LintState): string | null {
    if (scheduler.state === 'conflict') {
        return core.tr.t('toolbar.publish_conflict');
    }
    return hasErrors(lint.items) || scheduler.state === 'invalid'
        ? core.tr.t('toolbar.publish_blocked')
        : null;
}

/** Re-renders, feeds the preview and schedules autosaves on every store change. */
function followStore(
    core: Core,
    parts: {
        canvas: CanvasParts;
        preview: PreviewFeed | null;
        scheduler: SyncScheduler;
        hooks: Hooks;
    },
): () => void {
    return core.store.subscribe((state, change) => {
        parts.canvas.view.render(state);
        if (change.kind !== 'select' && change.kind !== 'meta') {
            parts.preview?.notify();
            if (change.kind !== 'transient') {
                parts.scheduler.notifyChange();
            }
        }
        parts.hooks.updateToolbar();
    });
}

function toolbarUpdater(
    core: Core,
    parts: { toolbar: Toolbar; scheduler: SyncScheduler; commands: Commands; lint: LintState },
): () => void {
    return () => {
        const { history } = core.store.state;
        parts.toolbar.update({
            canUndo: canUndo(history),
            canRedo: canRedo(history),
            publishBlocked: publishBlock(core, parts.scheduler, parts.lint),
            tablet: parts.commands.tablet,
        });
    };
}

/** "Go to properties": the first inspector field, or an announcement when there is none. */
function propertiesFocuser(core: Core, pane: HTMLElement | null): () => void {
    return () => {
        if (!focusFirstField(pane)) {
            core.announce(core.tr.t('announce.no_properties'));
        }
    };
}

export function mountBuilder(root: HTMLElement, options: MountOptions): BuilderApp {
    const core = createCore(root, options);
    const { store, win } = core;
    const canvas = createCanvas(core);
    const preview = createPreview(core);
    const lint: LintState = { items: [] };
    const hooks: Hooks = { updateToolbar: () => undefined, leaving: false };
    const scheduler = createScheduler(core, canvas, lint, hooks, options);
    const pane = win.document.querySelector<HTMLElement>('[data-builder-inspector]');
    const inspector = new InspectorGlue({
        store,
        wire: core.wire,
        tr: core.tr,
        announce: core.announce,
        pane,
        win,
    });
    const commands = createCommands(
        core,
        { canvas, scheduler, inspector, hooks, preview, lint },
        options,
    );
    const toolbar = new Toolbar(root, (command) => void commands.run(command), win.document);
    hooks.updateToolbar = toolbarUpdater(core, { toolbar, scheduler, commands, lint });
    const unbindPage = bindPage(root, {
        win,
        actions: canvas.actions,
        store,
        jsonDialog: root.querySelector('dialog[data-builder-json]'),
        isDirty: () => !hooks.leaving && !commands.leaving && scheduler.isDirty(),
        focusProperties: propertiesFocuser(core, pane),
    });
    const unsubscribe = followStore(core, { canvas, preview, scheduler, hooks });
    canvas.view.render();
    hooks.updateToolbar();
    void scheduler.validate();
    const destroy = (): void => {
        unsubscribe();
        unbindPage();
        canvas.destroy();
        [preview, scheduler, inspector, toolbar].forEach((part) => part?.destroy());
    };
    return { store, scheduler, commands, destroy };
}
