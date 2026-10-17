/**
 * Writes pending edits of open drafts to IndexedDB before a new service worker takes over (ADR
 * 0035). Uses `flushDraftSessions` of the draft module when it exports one; until then a synthetic
 * `pagehide` asks the autosave to flush, exactly like a page being hidden or closed.
 */
import * as draftSessions from '../drafts/draft-session.ts';

const exported: Readonly<Record<string, unknown>> = draftSessions;

export async function flushOpenDrafts(): Promise<void> {
    const flush = exported.flushDraftSessions;
    if (typeof flush === 'function') {
        await (flush as () => Promise<void>)();
        return;
    }
    window.dispatchEvent(new Event('pagehide'));
}
