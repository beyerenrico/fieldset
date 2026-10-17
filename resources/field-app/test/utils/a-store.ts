/** Offline-store fixtures for agent A's tests (ADR 0035): rows, scope, engine reset, locks. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { afterEach, beforeEach } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import type { AttachmentRow, DraftRow, Scope } from '../../src/lib/db/schema.ts';
import { setCurrentUserId } from '../../src/lib/db/scope.ts';
import { resetSyncEngineForTests } from '../../src/lib/sync/engine.ts';
import { resetSyncTriggersForTests } from '../../src/lib/sync/triggers.ts';
import { ORG_A, makeMe } from './fixtures.ts';

export const SCOPE: Scope = { orgId: 1, userId: 7 };

/**
 * meta 'session' as the last login of `userId` writes it (the engine and the local transport only
 * work for that user).
 */
export async function signInSession(userId: number = SCOPE.userId): Promise<void> {
    const me = makeMe([ORG_A]);
    await db.meta.put({
        key: 'session',
        value: { userId, me: { ...me, user: { ...me.user, id: userId } }, savedAt: Date.now() },
    });
}

/**
 * Signed-in user 7 (the fixtures' worker) for every test, also as the last login (meta 'session');
 * engine and triggers start clean. Call after setupTestServer() (it empties the store first).
 */
export function setupSyncScope(): void {
    beforeEach(async () => {
        setCurrentUserId(SCOPE.userId);
        resetSyncEngineForTests();
        await signInSession();
    });
    afterEach(() => {
        resetSyncTriggersForTests();
        setCurrentUserId(null);
    });
}

export function draftRow(overrides: Partial<DraftRow> = {}): DraftRow {
    const now = Date.now();
    return {
        uuid: '0192f1c4-0000-7000-8000-00000000a001',
        ...SCOPE,
        formId: 3,
        formVersionId: 7,
        data: {},
        localVersion: 1,
        syncedVersion: 0,
        serverRevision: 0,
        server: null,
        conflict: null,
        sync: 'queued',
        blockedBy: null,
        submitErrors: [],
        createdAt: now,
        updatedAt: now,
        ...overrides,
    };
}

export function attachmentRow(overrides: Partial<AttachmentRow> = {}): AttachmentRow {
    return {
        uuid: '0192f1c4-0000-7000-8000-00000000b001',
        submissionUuid: '0192f1c4-0000-7000-8000-00000000a001',
        ...SCOPE,
        kind: 'photo',
        elementKey: 'photos',
        fileName: 'photo.jpg',
        mime: 'image/jpeg',
        size: 4,
        bytes: new Uint8Array([1, 2, 3, 4]).buffer,
        state: 'pending',
        error: null,
        record: null,
        createdAt: Date.now(),
        ...overrides,
    };
}

export async function readDraft(uuid: string): Promise<DraftRow | undefined> {
    return db.drafts.get(uuid);
}

export async function outboxIds(): Promise<string[]> {
    return (await db.outbox.orderBy('seq').toArray()).map((op) => op.id);
}

export const data = (value: JsonObject): JsonObject => value;

interface FakeLockManager {
    request<T>(
        name: string,
        options: { ifAvailable: boolean },
        callback: (lock: unknown) => Promise<T>,
    ): Promise<T>;
}

/** A Web Locks stand-in shared by every "tab" of a test (jsdom has none). */
export function installFakeLocks(): { readonly granted: string[]; uninstall: () => void } {
    const held = new Set<string>();
    const granted: string[] = [];
    const locks: FakeLockManager = {
        async request(name, options, callback) {
            if (held.has(name) && options.ifAvailable) {
                return callback(null);
            }
            held.add(name);
            granted.push(name);
            try {
                return await callback({ name });
            } finally {
                held.delete(name);
            }
        },
    };
    Object.defineProperty(navigator, 'locks', { configurable: true, value: locks });
    return {
        granted,
        uninstall: () => {
            Reflect.deleteProperty(navigator, 'locks');
        },
    };
}

/**
 * Fake timers that leave setImmediate real: fake-indexeddb schedules its tasks with it, and a fake
 * clock that runs them in one synchronous tick (shouldAdvanceTime) commits Dexie transactions too
 * early (PrematureCommitError).
 */
export const FAKE_TIMERS = {
    shouldAdvanceTime: true,
    toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'],
} satisfies Parameters<typeof import('vitest').vi.useFakeTimers>[0];
