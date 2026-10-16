/** Reveal registry: brings a field into view (wizard page, virtualized row) and focuses it. */

/** A listener returns `true` when it changed something that needs a render before focusing. */
export type RevealListener = (pointer: string) => boolean;

export interface RevealRegistry {
    readonly register: (listener: RevealListener) => () => void;
    readonly reveal: (pointer: string, id: string) => void;
}

const MAX_ATTEMPTS = 10;

function focusTarget(id: string): HTMLElement | null {
    const input = document.getElementById(id);
    if (input !== null) {
        return input;
    }
    const field = document.getElementById(`${id}-field`);
    return field?.querySelector<HTMLElement>('input, select, textarea, button') ?? field;
}

function focusWhenReady(id: string, attempt: number): void {
    const target = focusTarget(id);
    if (target !== null) {
        target.focus();
        if (typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ block: 'center' });
        }
        return;
    }
    if (attempt < MAX_ATTEMPTS) {
        setTimeout(() => {
            focusWhenReady(id, attempt + 1);
        }, 16);
    }
}

export function createRevealRegistry(): RevealRegistry {
    const listeners = new Set<RevealListener>();
    return {
        register: (listener: RevealListener): (() => void) => {
            listeners.add(listener);
            return () => {
                listeners.delete(listener);
            };
        },
        reveal: (pointer: string, id: string): void => {
            let pending = false;
            for (const listener of listeners) {
                pending = listener(pointer) || pending;
            }
            if (pending) {
                setTimeout(() => {
                    focusWhenReady(id, 0);
                }, 0);
            } else {
                focusWhenReady(id, 0);
            }
        },
    };
}
