/**
 * Undo/redo (ADR 0037): up to 100 definition snapshots, each with a label and the selection.
 * Inspector updates to the same node within one second merge into one entry. Pure functions.
 */
import type { Definition } from './tree.ts';

export const HISTORY_LIMIT = 100;
export const MERGE_WINDOW_MS = 1000;

export type Selection =
    | { readonly kind: 'settings' }
    | { readonly kind: 'page'; readonly id: string }
    | { readonly kind: 'element'; readonly id: string }
    | null;

export interface HistoryEntry {
    readonly definition: Definition;
    readonly selection: Selection;
    /** What produced this state ("Move Text"); empty for the initial state. */
    readonly label: string;
    /** Entries with the same key recorded within MERGE_WINDOW_MS merge. */
    readonly mergeKey: string | null;
    readonly at: number;
}

export interface History {
    readonly past: readonly HistoryEntry[];
    readonly present: HistoryEntry;
    readonly future: readonly HistoryEntry[];
}

export function createHistory(definition: Definition, selection: Selection, now: number): History {
    return {
        past: [],
        present: { definition, selection, label: '', mergeKey: null, at: now },
        future: [],
    };
}

/** Records a new state; clears redo. Merges into the present entry when the merge key matches. */
export function record(history: History, entry: HistoryEntry): History {
    const { present } = history;
    const merge =
        entry.mergeKey !== null &&
        present.mergeKey === entry.mergeKey &&
        entry.at - present.at < MERGE_WINDOW_MS &&
        history.past.length > 0;
    if (merge) {
        return { past: history.past, present: { ...entry, label: present.label }, future: [] };
    }
    const past = [...history.past, present];
    return {
        past: past.slice(Math.max(0, past.length - (HISTORY_LIMIT - 1))),
        present: entry,
        future: [],
    };
}

export function canUndo(history: History): boolean {
    return history.past.length > 0;
}

export function canRedo(history: History): boolean {
    return history.future.length > 0;
}

/** Steps back; null when there is nothing to undo. The undone label is `history.present.label`. */
export function undo(history: History): History | null {
    const previous = history.past.at(-1);
    if (previous === undefined) {
        return null;
    }
    return {
        past: history.past.slice(0, -1),
        present: { ...previous, mergeKey: null },
        future: [history.present, ...history.future],
    };
}

export function redo(history: History): History | null {
    const [next, ...rest] = history.future;
    if (next === undefined) {
        return null;
    }
    return {
        past: [...history.past, history.present],
        present: { ...next, mergeKey: null },
        future: rest,
    };
}

/** Updates the selection of the present entry (no new undo step). */
export function withSelection(history: History, selection: Selection): History {
    return { ...history, present: { ...history.present, selection } };
}
