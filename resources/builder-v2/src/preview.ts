/**
 * Live preview (ADR 0037): the same `/form-preview/` iframe and postMessage protocol as v1, fed
 * from the store at most every 250 ms. The iframe is created once (inside `wire:ignore`) and never
 * re-created. It is sandboxed without allow-same-origin (opaque origin, ADR 0040): updates go to
 * its window with target origin '*', and a ready message is only trusted from that window with the
 * opaque origin 'null'.
 */
import type { PreviewUpdate } from '../../field-app/src/preview/protocol.ts';
import type { Definition } from './store/tree.ts';

/** Mirrors resources/field-app/src/preview/protocol.ts (checked by test/preview.test.ts). */
export const PREVIEW_MESSAGE = 'form-schema-preview';
export const PREVIEW_READY = 'form-schema-preview-ready';
export const PREVIEW_TARGET_ORIGIN = '*';
export const PREVIEW_SENDER_ORIGIN = 'null';
export const PREVIEW_INTERVAL_MS = 250;

export type PreviewSize = 'phone' | 'tablet';

export interface PreviewDeps {
    readonly getDefinition: () => Definition;
    readonly win?: Window;
    readonly now?: () => number;
}

export class PreviewFeed {
    readonly #frame: HTMLIFrameElement;
    readonly #deps: PreviewDeps;
    readonly #win: Window;
    #ready = false;
    #timer: ReturnType<typeof setTimeout> | null = null;
    #lastSentAt = Number.NEGATIVE_INFINITY;
    #lastSent: Definition | null = null;

    constructor(frame: HTMLIFrameElement, deps: PreviewDeps) {
        this.#frame = frame;
        this.#deps = deps;
        this.#win = deps.win ?? window;
        this.#win.addEventListener('message', this.#onMessage);
        frame.addEventListener('load', this.#onLoad);
        // The preview may already be listening (its ready message can precede this script).
        this.#send();
    }

    readonly #onLoad = (): void => {
        this.#lastSent = null;
        this.notify();
    };

    destroy(): void {
        this.#win.removeEventListener('message', this.#onMessage);
        this.#frame.removeEventListener('load', this.#onLoad);
        if (this.#timer !== null) {
            clearTimeout(this.#timer);
        }
    }

    readonly #onMessage = (event: MessageEvent): void => {
        const data: unknown = event.data;
        const isReady =
            typeof data === 'object' &&
            data !== null &&
            (data as { type?: unknown }).type === PREVIEW_READY;
        const frameWindow = this.#frame.contentWindow;
        if (
            !isReady ||
            event.origin !== PREVIEW_SENDER_ORIGIN ||
            frameWindow === null ||
            event.source !== frameWindow
        ) {
            return;
        }
        this.#ready = true;
        this.#lastSent = null;
        this.#send();
    };

    get ready(): boolean {
        return this.#ready;
    }

    #now(): number {
        return (this.#deps.now ?? Date.now)();
    }

    /** Schedules an update: leading edge when idle, then at most one per interval. */
    notify(): void {
        if (this.#timer !== null) {
            return;
        }
        const wait = this.#lastSentAt + PREVIEW_INTERVAL_MS - this.#now();
        if (wait <= 0) {
            this.#send();
            return;
        }
        this.#timer = setTimeout(() => {
            this.#timer = null;
            this.#send();
        }, wait);
    }

    #send(): void {
        const definition = this.#deps.getDefinition();
        const target = this.#frame.contentWindow;
        if (target === null || definition === this.#lastSent) {
            return;
        }
        const update: PreviewUpdate & { type: string } = {
            type: PREVIEW_MESSAGE,
            definition: definition as unknown as PreviewUpdate['definition'],
        };
        target.postMessage(update, PREVIEW_TARGET_ORIGIN);
        this.#lastSent = definition;
        this.#lastSentAt = this.#now();
    }

    setSize(size: PreviewSize): void {
        this.#frame.dataset.size = size;
    }
}
