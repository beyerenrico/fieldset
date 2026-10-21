/**
 * Inspector glue (ADR 0037): selecting a node calls `inspect(kind, id, node, scope)`; edits in the
 * Livewire inspector are committed with `commitInspector(scope)`, and the server answers with the
 * browser event `builder-v2:node-changed`, applied here as an `update` (children kept, merged
 * into one undo step per node within a second). `builder-v2:published` refreshes the locked ids.
 *
 * - A commit that the server refused (field errors) or that failed reports `false` from flush().
 * - node-changed waits while a keyboard grab holds transient moves, so Esc still restores them.
 * - When the inspected node disappears (delete, undo of an insert), the form settings are shown.
 * - Key suggestions arrive as `builder-v2:key-suggested` (see key-suggestion.ts).
 */
import type { JsonObject } from '@fieldset/fieldset-js';
import type { BuilderWire, InspectorKind, NodeChangedDetail, PublishedDetail } from './contract.ts';
import type { Translator } from './i18n.ts';
import { nodeLabel } from './canvas/labels.ts';
import {
    fillInput,
    isKeyInput,
    KEY_SUGGESTED_EVENT,
    keyInput,
    parseKeySuggestion,
} from './key-suggestion.ts';
import type { Selection } from './store/history.ts';
import { isObject, jsonEquals, omit, str } from './store/json.ts';
import { inspectorScope } from './store/scope.ts';
import type { BuilderStore, Change, StoreState } from './store/store.ts';
import { nodeEntry, type Definition } from './store/tree.ts';

export const NODE_CHANGED_EVENT = 'builder-v2:node-changed';
export const PUBLISHED_EVENT = 'builder-v2:published';
export const COMMIT_CHANGE_MS = 150;
export const COMMIT_INPUT_MS = 700;

const CHILD_KEYS = ['elements', 'columns', 'pages'];
const SETTINGS: Selection = { kind: 'settings' };

export interface InspectorDeps {
    readonly store: BuilderStore;
    readonly wire: Pick<BuilderWire, 'inspect' | 'commitInspector'>;
    readonly tr: Translator;
    readonly announce: (message: string) => void;
    /** The `[data-builder-inspector]` pane (outside the `wire:ignore` root). */
    readonly pane: HTMLElement | null;
    readonly win?: Window;
}

interface NodeChange {
    readonly kind: InspectorKind;
    readonly id: string | null;
    readonly node: JsonObject;
}

/** The node as the inspector sees it: own properties only. */
export function inspectedNode(definition: Definition, selection: Selection): JsonObject | null {
    if (selection === null) {
        return null;
    }
    if (selection.kind === 'settings') {
        return omit(definition, CHILD_KEYS);
    }
    const entry = nodeEntry(definition, selection.id);
    return entry === null ? null : omit(entry.node, CHILD_KEYS);
}

function sameSelection(a: Selection, b: Selection): boolean {
    if (a === null || b === null) {
        return a === b;
    }
    return a.kind === b.kind && (a.kind === 'settings' || (b.kind !== 'settings' && a.id === b.id));
}

/** A keyboard grab moved nodes without recording them yet (Esc restores the recorded state). */
function isTransient(state: StoreState): boolean {
    return state.definition !== state.history.present.definition;
}

function parseNodeChanged(detail: unknown): NodeChange | null {
    const value = (Array.isArray(detail) ? detail[0] : detail) as
        Partial<NodeChangedDetail> | undefined;
    if (
        !isObject(value) ||
        !['settings', 'page', 'element'].includes(String(value.kind)) ||
        typeof value.node !== 'string'
    ) {
        return null;
    }
    try {
        const node: unknown = JSON.parse(value.node);
        return isObject(node)
            ? { kind: value.kind as InspectorKind, id: str(value.id), node }
            : null;
    } catch {
        return null;
    }
}

export class InspectorGlue {
    readonly #deps: InspectorDeps;
    readonly #win: Window;
    readonly #unsubscribe: () => void;
    #timer: ReturnType<typeof setTimeout> | null = null;
    #commit: Promise<boolean> = Promise.resolve(true);
    #inFlight = 0;
    /** Whether the last commit was accepted (a node-changed event arrived for it). */
    #accepted = true;
    #received = false;
    /** node-changed events that arrived during a keyboard grab. */
    #queued: NodeChange[] = [];
    /** True while the glue itself writes into an inspector field. */
    #filling = false;
    /** The node the Livewire inspector currently shows (pending edits belong to it). */
    #inspected: Selection = null;
    /** The node last sent (or being sent) to `inspect()`. */
    #requested: Selection = null;

    constructor(deps: InspectorDeps) {
        this.#deps = deps;
        this.#win = deps.win ?? window;
        this.#unsubscribe = deps.store.subscribe(this.#onStore);
        this.#win.addEventListener(NODE_CHANGED_EVENT, this.#onNodeChanged);
        this.#win.addEventListener(PUBLISHED_EVENT, this.#onPublished);
        this.#win.addEventListener(KEY_SUGGESTED_EVENT, this.#onKeySuggested);
        deps.pane?.addEventListener('change', this.#onChange);
        deps.pane?.addEventListener('input', this.#onInput);
    }

    destroy(): void {
        this.#unsubscribe();
        this.#win.removeEventListener(NODE_CHANGED_EVENT, this.#onNodeChanged);
        this.#win.removeEventListener(PUBLISHED_EVENT, this.#onPublished);
        this.#win.removeEventListener(KEY_SUGGESTED_EVENT, this.#onKeySuggested);
        this.#deps.pane?.removeEventListener('change', this.#onChange);
        this.#deps.pane?.removeEventListener('input', this.#onInput);
        if (this.#timer !== null) {
            clearTimeout(this.#timer);
        }
    }

    /** Edits wait to be committed, or a commit is running. */
    get busy(): boolean {
        return this.#timer !== null || this.#inFlight > 0;
    }

    #scopeJson(state: StoreState, selection: Selection): string {
        return JSON.stringify(inspectorScope(state.definition, selection, state.autoKeyIds));
    }

    /**
     * Inspects on every explicit selection, after undo/redo, and whenever an operation selected
     * another node (a palette insert, a duplicate, a new page); never for its own updates. Shows the
     * form settings once the inspected node is gone; applies node changes held back by a grab.
     */
    readonly #onStore = (state: StoreState, change: Change): void => {
        const { selection } = state;
        const travelled = change.kind === 'undo' || change.kind === 'redo';
        if (
            change.kind === 'select' ||
            (selection !== null && (travelled || !sameSelection(selection, this.#requested)))
        ) {
            void this.inspect(state);
        } else if (
            selection === null &&
            this.#requested !== null &&
            inspectedNode(state.definition, this.#requested) === null
        ) {
            void this.inspect(state, SETTINGS);
        }
        if (this.#queued.length > 0 && !isTransient(state)) {
            queueMicrotask(this.#drain); // not inside this store notification
        }
    };

    /**
     * Sends the pending commit first, so edits never land on the next node, then inspects the
     * node as it is after that commit (skipped when a newer selection superseded it meanwhile).
     */
    async inspect(
        state: StoreState = this.#deps.store.state,
        target: Selection = state.selection,
    ): Promise<void> {
        this.#requested = target;
        await this.flush();
        const current = this.#deps.store.state;
        const node = inspectedNode(current.definition, target);
        if (target === null || node === null || !sameSelection(target, this.#requested)) {
            return;
        }
        const id = target.kind === 'settings' ? null : target.id;
        this.#inspected = target;
        if (!this.busy) {
            this.#accepted = true; // a refused edit of the previous node is gone with its fields
        }
        await this.#deps.wire.inspect(
            target.kind,
            id,
            JSON.stringify(node),
            this.#scopeJson(current, target),
        );
    }

    #schedule(delay: number): void {
        if (this.#timer !== null) {
            clearTimeout(this.#timer);
        }
        this.#timer = setTimeout(() => void this.flush(), delay);
    }

    readonly #onChange = (): void => {
        this.#schedule(COMMIT_CHANGE_MS);
    };

    readonly #onInput = (event: Event): void => {
        if (this.#filling) {
            return;
        }
        const inspected = this.#inspected;
        if (isKeyInput(event.target) && inspected !== null && inspected.kind === 'element') {
            this.#deps.store.setAutoKey(inspected.id, false); // the user owns the key now
        }
        this.#schedule(COMMIT_INPUT_MS);
    };

    /**
     * Commits pending inspector edits now; resolves to false when the last commit was refused
     * (field errors are shown in the inspector) or failed.
     */
    flush(): Promise<boolean> {
        const inspected = this.#inspected;
        if (this.#timer !== null) {
            clearTimeout(this.#timer);
            this.#timer = null;
            if (inspected !== null) {
                const scope = this.#scopeJson(this.#deps.store.state, inspected);
                this.#inFlight += 1;
                this.#commit = this.#commit.then(() => this.#commitOnce(scope));
            }
        }
        return this.#inFlight > 0 ? this.#commit : Promise.resolve(this.#accepted);
    }

    async #commitOnce(scope: string): Promise<boolean> {
        this.#received = false;
        try {
            await this.#deps.wire.commitInspector(scope);
            this.#accepted = this.#received; // Livewire dispatches before the call resolves
        } catch {
            this.#accepted = false;
        } finally {
            this.#inFlight -= 1;
        }
        return this.#accepted;
    }

    readonly #onNodeChanged = (event: Event): void => {
        const parsed = parseNodeChanged((event as CustomEvent<unknown>).detail);
        if (parsed === null) {
            return;
        }
        this.#received = true;
        if (isTransient(this.#deps.store.state)) {
            this.#queued = [...this.#queued, parsed];
        } else {
            this.apply(parsed.kind, parsed.id, parsed.node);
        }
    };

    readonly #drain = (): void => {
        if (isTransient(this.#deps.store.state)) {
            return;
        }
        const queued = this.#queued;
        this.#queued = [];
        queued.forEach((change) => {
            this.apply(change.kind, change.id, change.node);
        });
    };

    /** Applies an inspector result as an `update` (ignored when nothing changed). */
    apply(kind: InspectorKind, id: string | null, node: JsonObject): void {
        const { store, tr } = this.#deps;
        const selection: Selection =
            kind === 'settings' ? { kind } : id === null ? null : { kind, id };
        const current = inspectedNode(store.state.definition, selection);
        const props = omit(node, CHILD_KEYS);
        if (current === null || jsonEquals(current, props)) {
            return;
        }
        if (
            kind === 'element' &&
            id !== null &&
            store.state.autoKeyIds.has(id) &&
            props.key !== current.key &&
            props.label === current.label
        ) {
            store.setAutoKey(id, false);
        }
        const label = id === null ? tr.t('canvas.settings') : nodeLabel(props, tr);
        const refusal = store.dispatch(
            { op: 'update', kind, id, props },
            { label: tr.t('history.edit', { label }), mergeKey: `update:${kind}:${id ?? ''}` },
        );
        if (refusal !== null) {
            this.#deps.announce(tr.t('announce.refused', { reason: tr.t(`refusal.${refusal}`) }));
        }
    }

    /** Fills a suggested key, unless the key field changed since the server saw it. */
    readonly #onKeySuggested = (event: Event): void => {
        const suggestion = parseKeySuggestion((event as CustomEvent<unknown>).detail);
        const inspected = this.#inspected;
        const input = keyInput(this.#deps.pane);
        if (
            suggestion === null ||
            inspected?.kind !== 'element' ||
            inspected.id !== suggestion.id ||
            input === null ||
            input.disabled
        ) {
            return;
        }
        if (input.value !== suggestion.from) {
            this.#deps.store.setAutoKey(inspected.id, false); // typed while the request was out
            return;
        }
        this.#filling = true;
        try {
            fillInput(input, suggestion.key);
        } finally {
            this.#filling = false;
        }
        this.#schedule(COMMIT_CHANGE_MS);
    };

    readonly #onPublished = (event: Event): void => {
        const raw: unknown = (event as CustomEvent<unknown>).detail;
        const detail = (Array.isArray(raw) ? raw[0] : raw) as Partial<PublishedDetail> | undefined;
        if (!isObject(detail) || !Array.isArray(detail.lockedIds)) {
            return;
        }
        const ids = detail.lockedIds.filter((id): id is string => typeof id === 'string');
        const { store, tr } = this.#deps;
        ids.forEach((id) => {
            store.setAutoKey(id, false);
        });
        store.setLockedIds(ids);
        if (store.state.selection !== null) {
            void this.inspect(); // shows a key that is now published as locked
        }
        this.#deps.announce(
            tr.t('announce.published', {
                version: typeof detail.version === 'number' ? detail.version : 0,
            }),
        );
    };
}
