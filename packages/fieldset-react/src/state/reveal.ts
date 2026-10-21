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

/** Events that show the user moved on: a deferred focus must not pull them back. */
const USER_EVENTS = ['pointerdown', 'keydown', 'input'] as const;

/** Becomes `cancelled` on the first user interaction until `stop()` is called. */
function watchUser(): { readonly cancelled: () => boolean; readonly stop: () => void } {
    let moved = false;
    const mark = (): void => {
        moved = true;
    };
    for (const type of USER_EVENTS) {
        document.addEventListener(type, mark, true);
    }
    return {
        cancelled: () => moved,
        stop: () => {
            for (const type of USER_EVENTS) {
                document.removeEventListener(type, mark, true);
            }
        },
    };
}

type UserWatch = ReturnType<typeof watchUser>;

function focusWhenReady(id: string, attempt: number, watch: UserWatch): void {
    if (watch.cancelled()) {
        watch.stop();
        return;
    }
    const target = focusTarget(id);
    if (target !== null) {
        target.focus();
        if (typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ block: 'center' });
        }
        watch.stop();
        return;
    }
    if (attempt < MAX_ATTEMPTS) {
        setTimeout(() => {
            focusWhenReady(id, attempt + 1, watch);
        }, 16);
    } else {
        watch.stop();
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
            const watch = watchUser();
            let pending = false;
            for (const listener of listeners) {
                pending = listener(pointer) || pending;
            }
            if (pending) {
                setTimeout(() => {
                    focusWhenReady(id, 0, watch);
                }, 0);
            } else {
                focusWhenReady(id, 0, watch);
            }
        },
    };
}
