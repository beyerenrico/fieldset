/**
 * Storage persistence (ADR 0035 "Persistence and install", §9.2): ask the browser not to evict the
 * offline store, and report usage for Settings. Every call is best effort: browsers without the
 * Storage API (older Safari, jsdom) answer `null`.
 */

export interface StorageEstimate {
    /** Bytes used by this origin (IndexedDB, Cache Storage, …). */
    readonly usage: number;
    /** Bytes the browser lets this origin use. */
    readonly quota: number;
}

function storageManager(): StorageManager | null {
    return typeof navigator !== 'undefined' && 'storage' in navigator ? navigator.storage : null;
}

/** Whether the origin's storage is already persistent; null when unknown. */
export async function isPersisted(): Promise<boolean | null> {
    const manager = storageManager();
    if (manager === null || typeof manager.persisted !== 'function') {
        return null;
    }
    try {
        return await manager.persisted();
    } catch {
        return null;
    }
}

/**
 * Requests persistent storage once it is not granted yet (Chromium decides silently, Firefox asks).
 * Returns the resulting state; null when the browser has no Storage API.
 */
export async function persist(): Promise<boolean | null> {
    const manager = storageManager();
    if (manager === null || typeof manager.persist !== 'function') {
        return null;
    }
    try {
        if ((await isPersisted()) === true) {
            return true;
        }
        return await manager.persist();
    } catch {
        return null;
    }
}

/** Usage and quota of this origin; null when unknown. */
export async function estimate(): Promise<StorageEstimate | null> {
    const manager = storageManager();
    if (manager === null || typeof manager.estimate !== 'function') {
        return null;
    }
    try {
        const result = await manager.estimate();
        return { usage: result.usage ?? 0, quota: result.quota ?? 0 };
    } catch {
        return null;
    }
}

const UNITS = ['byte', 'kilobyte', 'megabyte', 'gigabyte'] as const;

/** Human-readable size in the UI language, e.g. "12.5 MB" (1 kB = 1000 bytes, as browsers report). */
export function formatBytes(bytes: number, language: string): string {
    let value = Math.max(0, bytes);
    let unit = 0;
    while (value >= 1000 && unit < UNITS.length - 1) {
        value /= 1000;
        unit++;
    }
    return new Intl.NumberFormat(language, {
        style: 'unit',
        unit: UNITS[unit],
        unitDisplay: 'short',
        maximumFractionDigits: unit === 0 ? 0 : 1,
    }).format(value);
}
