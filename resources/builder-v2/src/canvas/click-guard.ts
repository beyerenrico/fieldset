/**
 * Swallows the click a drag gesture may end with (the browser fires it on mouseup, at the common
 * ancestor of the press and release targets), and only that click: the guard is armed when a drag
 * ends and disarmed by the next pointer or key press, since a deliberate click starts with one,
 * or after a second at the latest. A fixed time window would also swallow quick deliberate clicks.
 */
export const CLICK_GUARD_MS = 1000;

export class DragClickGuard {
    readonly #doc: Document;
    #armed = false;
    #timer: ReturnType<typeof setTimeout> | null = null;

    constructor(doc: Document) {
        this.#doc = doc;
        doc.addEventListener('pointerdown', this.#disarm, true);
        doc.addEventListener('keydown', this.#disarm, true);
    }

    /** Call when a drag ended. */
    arm(): void {
        this.#disarm();
        this.#armed = true;
        this.#timer = setTimeout(this.#disarm, CLICK_GUARD_MS);
    }

    /** True, once, for the click that ends a drag. */
    consume(): boolean {
        const armed = this.#armed;
        this.#disarm();
        return armed;
    }

    readonly #disarm = (): void => {
        this.#armed = false;
        if (this.#timer !== null) {
            clearTimeout(this.#timer);
            this.#timer = null;
        }
    };

    destroy(): void {
        this.#disarm();
        this.#doc.removeEventListener('pointerdown', this.#disarm, true);
        this.#doc.removeEventListener('keydown', this.#disarm, true);
    }
}
