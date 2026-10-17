/**
 * Object URLs for attachment thumbnails. `<img src>` cannot send `X-Organization-Id`, so server URLs
 * are never handed to the renderer: a preview is a blob URL made from the local bytes or from an
 * API download. Every URL created here is revoked by `drop()` or `dispose()`.
 */

/** Loads the preview blob of one attachment; null (or a rejection) hides the thumbnail only. */
export type PreviewSource = (id: string) => Promise<Blob | null>;

function createUrl(blob: Blob): string | undefined {
    return typeof URL.createObjectURL === 'function' ? URL.createObjectURL(blob) : undefined;
}

function revokeUrl(url: string): void {
    if (typeof URL.revokeObjectURL === 'function') {
        URL.revokeObjectURL(url);
    }
}

export class PreviewUrls {
    private readonly urls = new Map<string, string>();
    /** Loads in flight → the generation that started them. */
    private readonly loading = new Map<string, number>();
    private readonly listeners = new Set<() => void>();
    private readonly source: PreviewSource;
    private readonly concurrency: number;
    private generation = 0;
    private changes = 0;

    constructor(source: PreviewSource, concurrency: number) {
        this.source = source;
        this.concurrency = Math.max(1, concurrency);
    }

    get version(): number {
        return this.changes;
    }

    url(id: string): string | undefined {
        return this.urls.get(id);
    }

    has(id: string): boolean {
        return this.urls.has(id);
    }

    /** Creates the URL for a blob already in memory (fresh uploads); listeners are not told. */
    set(id: string, blob: Blob): string | undefined {
        const url = createUrl(blob);
        if (url !== undefined) {
            this.urls.set(id, url);
        }
        return url;
    }

    drop(id: string): void {
        const url = this.urls.get(id);
        if (url !== undefined) {
            revokeUrl(url);
            this.urls.delete(id);
        }
    }

    /** Loads previews for `ids` without one, `concurrency` at a time; failures are ignored. */
    async load(ids: readonly string[]): Promise<void> {
        const run = this.generation;
        const queue = ids.filter((id) => !this.urls.has(id) && this.loading.get(id) !== run);
        const worker = async (): Promise<void> => {
            for (let id = queue.shift(); id !== undefined; id = queue.shift()) {
                await this.loadOne(id, run);
            }
        };
        const lanes = Math.min(this.concurrency, queue.length);
        await Promise.all(Array.from({ length: lanes }, worker));
    }

    subscribe(listener: () => void): () => void {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    }

    /** Revokes every URL; loads still in flight are ignored (a later `load()` recreates them). */
    dispose(): void {
        this.generation++;
        for (const url of this.urls.values()) {
            revokeUrl(url);
        }
        this.urls.clear();
        this.notify();
    }

    private notify(): void {
        this.changes++;
        for (const listener of this.listeners) {
            listener();
        }
    }

    private async loadOne(id: string, run: number): Promise<void> {
        this.loading.set(id, run);
        try {
            const blob = await this.source(id);
            if (
                blob !== null &&
                run === this.generation &&
                !this.urls.has(id) &&
                this.set(id, blob) !== undefined
            ) {
                this.notify();
            }
        } catch {
            // A missing preview only hides the thumbnail; the attachment itself is unaffected.
        } finally {
            if (this.loading.get(id) === run) {
                this.loading.delete(id);
            }
        }
    }
}
