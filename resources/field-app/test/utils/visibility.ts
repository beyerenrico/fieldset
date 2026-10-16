/** Page Visibility for tests: browsers fire a bubbling `visibilitychange` at the document. */
import { afterEach } from 'vitest';

export function setVisibility(state: DocumentVisibilityState): void {
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: state });
    document.dispatchEvent(new Event('visibilitychange', { bubbles: true }));
}

/** Every test starts with a visible tab. */
export function resetVisibilityAfterEach(): void {
    afterEach(() => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            value: 'visible',
        });
    });
}
