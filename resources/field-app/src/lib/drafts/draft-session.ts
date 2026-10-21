/**
 * One autosave controller, attachment adapter and work tracker per open draft, shared by the fill
 * and review screens. A session outlives its screens until pending edits are written on this
 * device. Logout and wipe end it without writing (the store is being cleared); a lost session
 * (reauth) writes pending edits on this device first, so expiry never loses data (ADR 0035): App.tsx
 * calls saveAndDisposeDraftSessions() then. An app update writes them and keeps the sessions open.
 * A session holds its draft's lock (draft-lock.ts); when another tab holds it, the session is
 * read-only and writes nothing.
 */
import type { JsonObject } from '@fieldset/fieldset-js';
import type { Submission } from '../api/types.ts';
import {
    createApiAttachmentAdapter,
    type ApiAttachmentAdapter,
} from '../attachments/api-adapter.ts';
import { AutosaveController } from '../autosave/controller.ts';
import { DraftLockedError } from '../autosave/errors.ts';
import { createLocalTransport } from '../autosave/local-transport.ts';
import type { SaveTransport } from '../autosave/types.ts';
import type { Scope } from '../db/schema.ts';
import { acquireDraftLock, type DraftLock } from './draft-lock.ts';
import { createWorkTracker, type WorkTracker } from './work-tracker.ts';

export interface DraftSession {
    readonly uuid: string;
    readonly controller: AutosaveController;
    readonly attachments: ApiAttachmentAdapter;
    readonly work: WorkTracker;
    /** `locked`: another tab edits this draft; this session is read-only. */
    readonly lock: DraftLock;
}

export interface DraftSessionInit {
    readonly uuid: string;
    readonly scope: Scope;
    readonly formId: number;
    readonly formVersionId: number;
    /** Latest server copy known (null: the draft is not on the server yet). */
    readonly submission: Submission | null;
    /** Data to start from (the local row's when it is newer than the server copy). */
    readonly data: JsonObject;
    /** Local version of an existing row (0: nothing written on this device yet). */
    readonly localVersion?: number;
    readonly transport?: SaveTransport;
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

/** Writes wait for the lock decision; a draft locked by another tab is never written here. */
function lockedTransport(transport: SaveTransport, lock: DraftLock): SaveTransport {
    return {
        save: async (request) => {
            if ((await lock.ready) === 'locked') {
                throw new DraftLockedError();
            }
            return transport.save(request);
        },
    };
}

function createSession(init: DraftSessionInit): DraftSession {
    const work = createWorkTracker();
    const lock = acquireDraftLock(init.uuid);
    const transport = lockedTransport(
        init.transport ??
            createLocalTransport({
                scope: init.scope,
                uuid: init.uuid,
                formId: init.formId,
                formVersionId: init.formVersionId,
                server: init.submission,
            }),
        lock,
    );
    const controller = new AutosaveController({
        uuid: init.uuid,
        formVersionId: init.formVersionId,
        data: init.data,
        transport,
        localVersion: init.localVersion ?? 0,
    });
    const attachments = createApiAttachmentAdapter({
        submissionUuid: init.uuid,
        initial: init.submission?.attachments ?? [],
        // Replaced by the screens with a localized message (setHandlers).
        ensureSaved: async () => {
            if ((await controller.flush({ ensureCreated: true })).state === 'error') {
                throw new Error('The draft could not be saved on this device.');
            }
        },
        track: work.track,
    });
    void lock.ready.then((state) => {
        if (state === 'locked') {
            controller.dispose(); // read-only: edits are ignored, nothing is written
        }
    });
    return { uuid: init.uuid, controller, attachments, work, lock };
}

/** Ends the session's resources without writing (its entry already left the map). */
function release(session: DraftSession): void {
    session.controller.dispose();
    session.attachments.dispose();
    session.lock.release();
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

/** The open session of a draft, if any (a reopened screen keeps its in-memory edits). */
export function findDraftSession(uuid: string): DraftSession | undefined {
    return entries.get(uuid)?.session;
}

async function close(entry: Entry): Promise<void> {
    const { uuid, controller, work } = entry.session;
    const unused = (): boolean => entries.get(uuid) === entry && entry.users === 0;
    try {
        for (;;) {
            const visits = entry.visits;
            await work.idle();
            if (!unused()) {
                return;
            }
            await controller.flush();
            await controller.settled();
            if (!unused()) {
                return;
            }
            if (entry.visits === visits) {
                break;
            }
        }
        entries.delete(uuid);
        release(entry.session);
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

/** Ends one draft's session without writing (its local rows were discarded or copied). */
export function endDraftSession(uuid: string): void {
    const entry = entries.get(uuid);
    if (entry !== undefined) {
        entries.delete(uuid);
        release(entry.session);
    }
}

/** Ends every session without writing (logout, wipe, next login: the store is cleared). */
export function disposeDraftSessions(): void {
    for (const { session } of entries.values()) {
        release(session);
    }
    entries.clear();
}

/**
 * Lost session (reauth): writes every pending edit on this device, then ends the sessions. The
 * entries leave the map at once, so a dispose that follows in the same tick finds nothing.
 */
export function saveAndDisposeDraftSessions(): Promise<void> {
    const sessions = [...entries.values()].map((entry) => entry.session);
    entries.clear();
    return Promise.all(
        sessions.map(async (session) => {
            try {
                await session.controller.stop();
            } finally {
                release(session);
            }
        }),
    ).then(() => undefined);
}

/**
 * Writes the pending edits of every open draft on this device and keeps the sessions open (a new
 * service worker takes over and reloads the page; see pwa/sw-update.ts).
 */
export async function flushDraftSessions(): Promise<void> {
    const sessions = [...entries.values()].map((entry) => entry.session);
    await Promise.all(sessions.map(({ controller }) => controller.flush()));
}
