/**
 * Asynchronous edits in flight (uploads, signature, location). Their target paths follow removed
 * repeater rows, so a late result lands in the row it was started in, or nowhere when that row is
 * gone. The registry lives as long as the renderer, not the field (wizard pages unmount fields).
 */
import type { ValueUpdater } from '../context.tsx';
import type { DataPath } from '../data/path.ts';

/** Handle of one asynchronous edit. */
export interface PendingEdit {
    /** Writes into the field's current location; `false` when its row was removed. */
    update(updater: ValueUpdater): boolean;
    /** Ends tracking (call once, when the work settled). */
    finish(): void;
}

export interface PendingEditRegistry {
    /** Tracks the value at `path`; `path()` is `null` once its row was removed. */
    readonly track: (path: DataPath) => { readonly path: () => DataPath | null; end: () => void };
    /** A repeater row was removed: later rows move up, edits inside the row are dropped. */
    readonly rowRemoved: (arrayPath: DataPath, index: number) => void;
}

/** `path` after removing row `index` of the repeater at `arrayPath`. */
export function shiftPath(
    path: DataPath | null,
    arrayPath: DataPath,
    index: number,
): DataPath | null {
    if (
        path === null ||
        path.length <= arrayPath.length ||
        !arrayPath.every((segment, position) => path[position] === segment)
    ) {
        return path;
    }
    const row = path[arrayPath.length];
    if (typeof row !== 'number' || row < index) {
        return path;
    }
    return row === index ? null : [...arrayPath, row - 1, ...path.slice(arrayPath.length + 1)];
}

export function createPendingEdits(): PendingEditRegistry {
    const paths = new Map<object, DataPath | null>();
    return {
        track: (path) => {
            const key = {};
            paths.set(key, path);
            return {
                path: () => paths.get(key) ?? null,
                end: () => {
                    paths.delete(key);
                },
            };
        },
        rowRemoved: (arrayPath, index) => {
            for (const [key, path] of paths) {
                paths.set(key, shiftPath(path, arrayPath, index));
            }
        },
    };
}

/**
 * Starts an edit: `write` applies a value only where its rows still exist; `onAsyncWork` gets a
 * promise that resolves (never rejects) when the edit finishes.
 */
export function beginEdit(
    registry: PendingEditRegistry,
    path: DataPath,
    write: (path: DataPath, updater: ValueUpdater) => boolean,
    onAsyncWork: ((work: Promise<void>) => void) | undefined,
): PendingEdit {
    const tracked = registry.track(path);
    let settle: () => void = () => undefined;
    const work = new Promise<void>((resolve) => {
        settle = resolve;
    });
    onAsyncWork?.(work);
    return {
        update: (updater) => {
            const current = tracked.path();
            return current !== null && write(current, updater);
        },
        finish: () => {
            tracked.end();
            settle();
        },
    };
}
