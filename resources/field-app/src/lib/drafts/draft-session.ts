/**
 * One autosave controller, attachment adapter and work tracker per open draft, shared by the fill
 * and review screens. A session outlives its screens until pending edits are written and saved
 * (offline: until a retry went through); logout, wipe and a lost session end it without saving.
 */
import type { Submission } from '../api/types.ts';
import {
    createApiAttachmentAdapter,
    type ApiAttachmentAdapter,
} from '../attachments/api-adapter.ts';
import { apiTransport } from '../autosave/api-transport.ts';
import { AutosaveController } from '../autosave/controller.ts';
import type { SaveTransport } from '../autosave/types.ts';
import { createWorkTracker, type WorkTracker } from './work-tracker.ts';

export interface DraftSession {
    readonly uuid: string;
    readonly controller: AutosaveController;
    readonly attachments: ApiAttachmentAdapter;
    readonly work: WorkTracker;
}

export interface DraftSessionInit {
    readonly uuid: string;
    readonly formVersionId: number;
    /** Server copy to start from (null: the draft does not exist yet). */
    readonly submission: Submission | null;
    readonly transport?: SaveTransport;
    readonly onSaved?: (submission: Submission) => void;
    readonly onAuthLost?: (error: unknown) => void;
}

interface Entry {
    readonly session: DraftSession;
    users: number;
    /** Counts retains: a running close starts over when a screen came back meanwhile. */
    visits: number;
    closing: boolean;
    started: boolean;
}

const entries = new Map<string, Entry>();

function createSession(init: DraftSessionInit): DraftSession {
    const work = createWorkTracker();
    const controller = new AutosaveController({
        uuid: init.uuid,
        formVersionId: init.formVersionId,
        revision: init.submission?.revision ?? 0,
        data: init.submission?.data ?? {},
        transport: init.transport ?? apiTransport,
        ...(init.onSaved === undefined ? {} : { onSaved: init.onSaved }),
        ...(init.onAuthLost === undefined ? {} : { onAuthLost: init.onAuthLost }),
    });
    const attachments = createApiAttachmentAdapter({
        submissionUuid: init.uuid,
        initial: init.submission?.attachments ?? [],
        // Replaced by the screens with a localized message (setHandlers).
        ensureSaved: async () => {
            if ((await controller.flush({ ensureCreated: true })).revision === 0) {
                throw new Error('The draft could not be saved.');
            }
        },
        track: work.track,
    });
    return { uuid: init.uuid, controller, attachments, work };
}

/** The open session of a draft, created from `init` when there is none (safe during render). */
export function openDraftSession(init: DraftSessionInit): DraftSession {
    const existing = entries.get(init.uuid);
    if (existing !== undefined) {
        return existing.session;
    }
    const entry: Entry = {
        session: createSession(init),
        users: 0,
        visits: 0,
        closing: false,
        started: false,
    };
    entries.set(init.uuid, entry);
    return entry.session;
}

async function close(entry: Entry): Promise<void> {
    const { uuid, controller, work, attachments } = entry.session;
    const unused = (): boolean => entries.get(uuid) === entry && entry.users === 0;
    try {
        for (;;) {
            const visits = entry.visits;
            await work.idle();
            if (!unused()) {
                return;
            }
            if (!controller.sessionLost) {
                await controller.flush();
                await controller.settled();
            }
            if (!unused()) {
                return;
            }
            if (entry.visits === visits) {
                break;
            }
        }
        entries.delete(uuid);
        controller.dispose();
        attachments.dispose();
    } finally {
        entry.closing = false;
    }
}

/** Marks the session as used by a mounted screen; call the returned function on unmount. */
export function retainDraftSession(session: DraftSession): () => void {
    const entry = entries.get(session.uuid);
    if (entry?.session !== session) {
        return () => undefined;
    }
    entry.users += 1;
    entry.visits += 1;
    if (!entry.started) {
        entry.started = true;
        session.controller.start();
    }
    let released = false;
    return () => {
        if (released) {
            return;
        }
        released = true;
        entry.users -= 1;
        if (entry.users === 0 && !entry.closing) {
            entry.closing = true;
            void close(entry);
        }
    };
}

/** Ends every session without saving (logout, wipe, lost session, next login). */
export function disposeDraftSessions(): void {
    for (const { session } of entries.values()) {
        session.controller.dispose();
        session.attachments.dispose();
    }
    entries.clear();
}
