import type { JsonObject } from '@fieldset/fieldset-js';
import { describe, expect, it } from 'vitest';
import {
    canRedo,
    canUndo,
    createHistory,
    HISTORY_LIMIT,
    record,
    redo,
    undo,
    withSelection,
    type History,
    type HistoryEntry,
} from '../src/store/history.ts';

const def = (n: number): JsonObject => ({ n });
const entry = (n: number, at: number, mergeKey: string | null = null): HistoryEntry => ({
    definition: def(n),
    selection: { kind: 'element', id: `id-${String(n)}` },
    label: `step ${String(n)}`,
    mergeKey,
    at,
});

describe('history', () => {
    it('undoes and redoes with labels and selection', () => {
        let history: History = createHistory(def(0), null, 0);
        history = record(history, entry(1, 10));
        history = record(history, entry(2, 20));
        expect(canUndo(history)).toBe(true);
        expect(history.present.label).toBe('step 2');
        const back = undo(history)!;
        expect(back.present.definition).toEqual(def(1));
        expect(back.present.selection).toEqual({ kind: 'element', id: 'id-1' });
        expect(canRedo(back)).toBe(true);
        const forward = redo(back)!;
        expect(forward.present.definition).toEqual(def(2));
        expect(redo(forward)).toBeNull();
        expect(undo(createHistory(def(0), null, 0))).toBeNull();
    });

    it('a new operation clears redo', () => {
        let history = record(record(createHistory(def(0), null, 0), entry(1, 10)), entry(2, 20));
        history = undo(history)!;
        history = record(history, entry(3, 30));
        expect(canRedo(history)).toBe(false);
        expect(undo(history)!.present.definition).toEqual(def(1));
    });

    it('merges updates to the same node within one second', () => {
        let history = record(createHistory(def(0), null, 0), entry(1, 1000, 'update:a'));
        history = record(history, entry(2, 1500, 'update:a'));
        history = record(history, entry(3, 2400, 'update:a'));
        expect(history.past).toHaveLength(1);
        expect(history.present.definition).toEqual(def(3));
        expect(history.present.label).toBe('step 1');
        history = record(history, entry(4, 3500, 'update:a'));
        expect(history.past).toHaveLength(2);
        history = record(history, entry(5, 3600, 'update:b'));
        expect(history.past).toHaveLength(3);
        expect(undo(history)!.present.definition).toEqual(def(4));
    });

    it('does not merge into an entry reached by undo', () => {
        let history = record(createHistory(def(0), null, 0), entry(1, 0, 'update:a'));
        history = record(history, entry(2, 100, 'update:b'));
        history = undo(history)!;
        history = record(history, entry(3, 200, 'update:a'));
        expect(history.past).toHaveLength(2);
    });

    it('keeps at most 100 snapshots', () => {
        let history = createHistory(def(0), null, 0);
        for (let n = 1; n <= 150; n++) {
            history = record(history, entry(n, n * 2000));
        }
        expect(history.past.length + 1).toBe(HISTORY_LIMIT);
        expect(history.past[0]!.definition).toEqual(def(51));
    });

    it('updates the selection without a new step', () => {
        const history = withSelection(createHistory(def(0), null, 0), { kind: 'settings' });
        expect(history.present.selection).toEqual({ kind: 'settings' });
        expect(canUndo(history)).toBe(false);
    });
});
