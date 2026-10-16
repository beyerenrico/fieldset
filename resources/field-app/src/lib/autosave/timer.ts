/** One cancellable timeout (debounce or retry of the autosave controller). */
export class Timer {
    private handle: ReturnType<typeof setTimeout> | null = null;

    get active(): boolean {
        return this.handle !== null;
    }

    start(ms: number, callback: () => void): void {
        this.clear();
        this.handle = setTimeout(() => {
            this.handle = null;
            callback();
        }, ms);
    }

    clear(): void {
        if (this.handle !== null) {
            clearTimeout(this.handle);
            this.handle = null;
        }
    }
}
