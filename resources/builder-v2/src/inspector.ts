/**
 * Inspector glue (ADR 0037): selecting a node calls `inspect(kind, id, node, scope)`; edits in the
 * Livewire inspector are committed with `commitInspector(scope)`, and the server answers with the
 * browser event `builder-v2:node-changed`, applied here as an `update` (children kept, merged
 * into one undo step per node within a second). `builder-v2:published` refreshes the locked ids.
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { BuilderWire, InspectorKind, NodeChangedDetail, PublishedDetail } from './contract.ts';
import type { Translator } from './i18n.ts';
import { nodeLabel } from './canvas/labels.ts';
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

export interface InspectorDeps {
    readonly store: BuilderStore;
    readonly wire: Pick<BuilderWire, 'inspect' | 'commitInspector'>;
    readonly tr: Translator;
    readonly announce: (message: string) => void;
    /** The `[data-builder-inspector]` pane (outside the `wire:ignore` root). */
    readonly pane: HTMLElement | null;
    readonly win?: Window;
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

function parseNodeChanged(
    detail: unknown,
): { kind: InspectorKind; id: string | null; node: JsonObject } | null {
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
    #commit: Promise<void> = Promise.resolve();
    /** The node the Livewire inspector currently shows (pending edits belong to it). */
    #inspected: Selection = null;

    constructor(deps: InspectorDeps) {
        this.#deps = deps;
        this.#win = deps.win ?? window;
        this.#unsubscribe = deps.store.subscribe(this.#onStore);
        this.#win.addEventListener(NODE_CHANGED_EVENT, this.#onNodeChanged);
        this.#win.addEventListener(PUBLISHED_EVENT, this.#onPublished);
        deps.pane?.addEventListener('change', this.#onChange);
        deps.pane?.addEventListener('input', this.#onInput);
    }

    destroy(): void {
        this.#unsubscribe();
        this.#win.removeEventListener(NODE_CHANGED_EVENT, this.#onNodeChanged);
        this.#win.removeEventListener(PUBLISHED_EVENT, this.#onPublished);
        this.#deps.pane?.removeEventListener('change', this.#onChange);
        this.#deps.pane?.removeEventListener('input', this.#onInput);
        if (this.#timer !== null) {
            clearTimeout(this.#timer);
        }
    }

    #scopeJson(state: StoreState, selection: Selection): string {
        return JSON.stringify(inspectorScope(state.definition, selection, state.autoKeyIds));
    }

    readonly #onStore = (state: StoreState, change: Change): void => {
        if (
            change.kind === 'select' ||
            ((change.kind === 'undo' || change.kind === 'redo') && state.selection !== null)
        ) {
            void this.inspect(state);
        }
    };

    /** Sends the pending commit first, so edits never land on the next node. */
    async inspect(state: StoreState = this.#deps.store.state): Promise<void> {
        await this.flush();
        const { selection } = state;
        const node = inspectedNode(state.definition, selection);
        if (selection === null || node === null) {
            return;
        }
        const id = selection.kind === 'settings' ? null : selection.id;
        this.#inspected = selection;
        await this.#deps.wire.inspect(
            selection.kind,
            id,
            JSON.stringify(node),
            this.#scopeJson(state, selection),
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

    readonly #onInput = (): void => {
        this.#schedule(COMMIT_INPUT_MS);
    };

    /** Commits pending inspector edits now (no-op when nothing is pending). */
    flush(): Promise<void> {
        if (this.#timer === null) {
            return this.#commit;
        }
        clearTimeout(this.#timer);
        this.#timer = null;
        const inspected = this.#inspected;
        if (inspected !== null) {
            const scope = this.#scopeJson(this.#deps.store.state, inspected);
            this.#commit = this.#commit
                .then(() => this.#deps.wire.commitInspector(scope))
                .catch(() => undefined);
        }
        return this.#commit;
    }

    readonly #onNodeChanged = (event: Event): void => {
        const parsed = parseNodeChanged((event as CustomEvent<unknown>).detail);
        if (parsed !== null) {
            this.apply(parsed.kind, parsed.id, parsed.node);
        }
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
        this.#deps.announce(
            tr.t('announce.published', {
                version: typeof detail.version === 'number' ? detail.version : 0,
            }),
        );
    };
}
