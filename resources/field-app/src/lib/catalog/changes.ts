/**
 * "The catalog of organization X changed" notifications (this tab). `shortcuts`: favorites/recent
 * were purged from the manifest; `forms`: the cached forms changed (refetch the forms list).
 */
export type CatalogChange = 'shortcuts' | 'forms';
type Listener = (orgId: number, change: CatalogChange) => void;

const listeners = new Set<Listener>();

export function onCatalogChanged(listener: Listener): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

export function emitCatalogChange(orgId: number, change: CatalogChange): void {
    for (const listener of listeners) {
        listener(orgId, change);
    }
}
