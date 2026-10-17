/** The single polite live region (ADR 0037): moves, inserts, deletes, undo and refusals. */

const NO_BREAK_SPACE = String.fromCharCode(0xa0);

export class Announcer {
    readonly #region: HTMLElement | null;
    #last = '';

    constructor(region: HTMLElement | null) {
        this.#region = region;
        if (region !== null) {
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
        }
    }

    /** Repeating the same message toggles a trailing no-break space so screen readers re-read it. */
    announce(message: string): void {
        if (this.#region === null || message === '') {
            return;
        }
        const text = message === this.#last ? message + NO_BREAK_SPACE : message;
        this.#region.textContent = text;
        this.#last = text;
    }

    get last(): string {
        return this.#region?.textContent ?? '';
    }
}
