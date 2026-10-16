/**
 * Tiny IndexedDB key-value store. M6 replaces it with Dexie (outbox, cached forms); keep the API
 * small so callers do not depend on IndexedDB details.
 */
export const DB_NAME = 'form-schema-field-app';
const STORE = 'kv';

let connection: Promise<IDBDatabase> | null = null;

function promisify<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => {
            resolve(request.result);
        };
        request.onerror = () => {
            reject(request.error ?? new Error('IndexedDB request failed'));
        };
    });
}

function open(): Promise<IDBDatabase> {
    connection ??= new Promise<IDBDatabase>((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE);
        };
        request.onsuccess = () => {
            const db = request.result;
            db.onversionchange = () => {
                db.close();
                connection = null;
            };
            resolve(db);
        };
        request.onerror = () => {
            connection = null;
            reject(request.error ?? new Error('IndexedDB open failed'));
        };
    });
    return connection;
}

async function withStore<T>(
    mode: IDBTransactionMode,
    run: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    const db = await open();
    return promisify(run(db.transaction(STORE, mode).objectStore(STORE)));
}

export async function kvGet<T>(key: string): Promise<T | undefined> {
    return (await withStore('readonly', (store) => store.get(key))) as T | undefined;
}

export async function kvSet(key: string, value: unknown): Promise<void> {
    await withStore('readwrite', (store) => store.put(value, key));
}

export async function kvDelete(key: string): Promise<void> {
    await withStore('readwrite', (store) => store.delete(key));
}

export async function kvClear(): Promise<void> {
    await withStore('readwrite', (store) => store.clear());
}

/** Closes the connection and deletes the whole database. */
export async function wipeAll(): Promise<void> {
    const pending = connection;
    connection = null;
    if (pending !== null) {
        try {
            (await pending).close();
        } catch {
            // The open failed; nothing to close.
        }
    }
    await promisify(indexedDB.deleteDatabase(DB_NAME));
}
