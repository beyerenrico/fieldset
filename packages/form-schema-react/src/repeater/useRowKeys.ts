/** Stable React keys for repeater rows: component state moves with its row when rows are removed. */
import { useState } from 'react';

interface RowKeys {
    readonly keys: readonly string[];
    readonly next: number;
}

function fresh(state: RowKeys, count: number): RowKeys {
    if (state.keys.length >= count) {
        return { ...state, keys: state.keys.slice(0, count) };
    }
    const added = Array.from(
        { length: count - state.keys.length },
        (_, offset) => `r${String(state.next + offset)}`,
    );
    return { keys: [...state.keys, ...added], next: state.next + added.length };
}

export interface UseRowKeys {
    readonly keys: readonly string[];
    /** Forgets the key of a removed row (call in the same event as the data update). */
    readonly drop: (index: number) => void;
}

/**
 * Keys follow the row count: new rows get fresh keys, rows removed from outside lose the last keys,
 * and `drop(index)` removes exactly the key of a row the user removed.
 */
export function useRowKeys(count: number): UseRowKeys {
    const [state, setState] = useState<RowKeys>(() => fresh({ keys: [], next: 0 }, count));
    let current = state;
    if (state.keys.length !== count) {
        current = fresh(state, count);
        setState(current);
    }
    return {
        keys: current.keys,
        drop: (index) => {
            setState((previous) => ({
                ...previous,
                keys: previous.keys.filter((_, position) => position !== index),
            }));
        },
    };
}
