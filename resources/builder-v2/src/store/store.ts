/**
 * The working copy (ADR 0037): one mutable cell holding immutable state (definition, selection,
 * history, locked ids). Operations are validated by the nesting rules before they apply.
 */
import type { Operation } from '../contract.ts';
import {
    createHistory,
    record,
    redo,
    undo,
    withSelection,
    type History,
    type Selection,
} from './history.ts';
import { normalizeIds } from './ids.ts';
import { applyOperation, OperationError } from './ops.ts';
import { checkOperation, type Refusal } from './rules.ts';
import { nodeEntry, type Definition } from './tree.ts';

export interface StoreState {
    readonly definition: Definition;
    readonly selection: Selection;
    readonly history: History;
    readonly lockedIds: ReadonlySet<string>;
    /** New palette nodes whose key still follows the label. */
    readonly autoKeyIds: ReadonlySet<string>;
}

export type ChangeKind = 'op' | 'transient' | 'restore' | 'undo' | 'redo' | 'select' | 'meta';

export interface Change {
    readonly kind: ChangeKind;
    readonly label: string;
    readonly op: Operation | null;
}

export type Listener = (state: StoreState, change: Change) => void;

export interface DispatchOptions {
    readonly label: string;
    readonly mergeKey?: string;
    readonly select?: Selection;
}

export interface StoreOptions {
    readonly maxContainerDepth: number;
    readonly lockedIds?: readonly string[];
    readonly now?: () => number;
}

function validSelection(definition: Definition, selection: Selection): Selection {
    if (selection === null || selection.kind === 'settings') {
        return selection;
    }
    return nodeEntry(definition, selection.id)?.kind === selection.kind ? selection : null;
}

export class BuilderStore {
    #state: StoreState;
    readonly #listeners = new Set<Listener>();
    readonly #now: () => number;
    readonly maxContainerDepth: number;

    constructor(definition: Definition, options: StoreOptions) {
        this.#now = options.now ?? Date.now;
        this.maxContainerDepth = options.maxContainerDepth;
        const normalized = normalizeIds(definition);
        this.#state = {
            definition: normalized,
            selection: null,
            history: createHistory(normalized, null, this.#now()),
            lockedIds: new Set(options.lockedIds ?? []),
            autoKeyIds: new Set(),
        };
    }

    get state(): StoreState {
        return this.#state;
    }

    subscribe(listener: Listener): () => void {
        this.#listeners.add(listener);
        return () => {
            this.#listeners.delete(listener);
        };
    }

    #set(state: StoreState, change: Change): void {
        this.#state = state;
        this.#listeners.forEach((listener) => {
            listener(state, change);
        });
    }

    #apply(op: Operation): Definition | Refusal {
        const refusal = checkOperation(this.#state.definition, op, this.maxContainerDepth);
        if (refusal !== null) {
            return refusal;
        }
        try {
            return applyOperation(this.#state.definition, op);
        } catch (error) {
            if (error instanceof OperationError) {
                return 'unknown_target';
            }
            throw error;
        }
    }

    /** Validates and applies an operation as one undo step; returns the refusal reason if any. */
    dispatch(op: Operation, options: DispatchOptions): Refusal | null {
        const result = this.#apply(op);
        if (typeof result === 'string') {
            return result;
        }
        const selection = validSelection(result, options.select ?? this.#state.selection);
        const history = record(this.#state.history, {
            definition: result,
            selection,
            label: options.label,
            mergeKey: options.mergeKey ?? null,
            at: this.#now(),
        });
        this.#set(
            { ...this.#state, definition: result, selection, history },
            { kind: 'op', label: options.label, op },
        );
        return null;
    }

    /** Applies an operation without an undo step (keyboard pick-up); see commit()/restore(). */
    applyTransient(op: Operation): Refusal | null {
        const result = this.#apply(op);
        if (typeof result === 'string') {
            return result;
        }
        this.#set({ ...this.#state, definition: result }, { kind: 'transient', label: '', op });
        return null;
    }

    /** Records the current (transient) definition as one undo step. */
    commit(label: string): void {
        const { definition, selection } = this.#state;
        if (definition === this.#state.history.present.definition) {
            return;
        }
        const history = record(this.#state.history, {
            definition,
            selection,
            label,
            mergeKey: null,
            at: this.#now(),
        });
        this.#set({ ...this.#state, history }, { kind: 'op', label, op: null });
    }

    /** Drops transient changes: back to the last recorded definition. */
    restore(): void {
        const definition = this.#state.history.present.definition;
        if (definition !== this.#state.definition) {
            this.#set({ ...this.#state, definition }, { kind: 'restore', label: '', op: null });
        }
    }

    /** Undoes one step; returns its label, or null when there is nothing to undo. */
    undo(): string | null {
        return this.#travel(undo(this.#state.history), 'undo', this.#state.history.present.label);
    }

    redo(): string | null {
        const label = this.#state.history.future[0]?.label ?? '';
        return this.#travel(redo(this.#state.history), 'redo', label);
    }

    #travel(history: History | null, kind: 'undo' | 'redo', label: string): string | null {
        if (history === null) {
            return null;
        }
        const { definition } = history.present;
        const selection = validSelection(definition, history.present.selection);
        this.#set({ ...this.#state, definition, selection, history }, { kind, label, op: null });
        return label;
    }

    select(selection: Selection): void {
        const valid = validSelection(this.#state.definition, selection);
        const history = withSelection(this.#state.history, valid);
        this.#set(
            { ...this.#state, selection: valid, history },
            { kind: 'select', label: '', op: null },
        );
    }

    setLockedIds(ids: readonly string[]): void {
        this.#set(
            { ...this.#state, lockedIds: new Set(ids) },
            { kind: 'meta', label: '', op: null },
        );
    }

    setAutoKey(id: string, on: boolean): void {
        const autoKeyIds = new Set(this.#state.autoKeyIds);
        if (on) {
            autoKeyIds.add(id);
        } else {
            autoKeyIds.delete(id);
        }
        this.#state = { ...this.#state, autoKeyIds };
    }
}
