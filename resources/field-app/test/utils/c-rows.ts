/** Offline-store rows and browser API stubs for the account/session tests (agent C). */
import { vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import type { AttachmentRow, DraftRow, OutboxRow, Scope } from '../../src/lib/db/schema.ts';

export const SCOPE: Scope = { orgId: 1, userId: 7 };

export function draftRow(uuid: string, overrides: Partial<DraftRow> = {}): DraftRow {
    return {
        ...SCOPE,
        uuid,
        formId: 3,
        formVersionId: 7,
        data: {},
        localVersion: 2,
        syncedVersion: 1,
        serverRevision: 1,
        server: null,
        conflict: null,
        sync: 'queued',
        blockedBy: null,
        submitErrors: [],
        createdAt: 1,
        updatedAt: 1,
        ...overrides,
    };
}

export function saveOp(submissionUuid: string, overrides: Partial<OutboxRow> = {}): OutboxRow {
    return {
        ...SCOPE,
        id: `draft.save:${submissionUuid}`,
        kind: 'draft.save',
        submissionUuid,
        seq: 1,
        state: 'pending',
        attempts: 0,
        nextAttemptAt: 0,
        lastError: null,
        createdAt: 1,
        ...overrides,
    } as OutboxRow;
}

export function attachmentRow(uuid: string, overrides: Partial<AttachmentRow> = {}): AttachmentRow {
    return {
        ...SCOPE,
        uuid,
        submissionUuid: 's1',
        kind: 'photo',
        elementKey: 'photos',
        fileName: 'a.jpg',
        mime: 'image/jpeg',
        size: 3,
        bytes: new ArrayBuffer(3),
        state: 'pending',
        error: null,
        record: null,
        createdAt: 1,
        ...overrides,
    };
}

/** One unsynced draft with its save op and one pending photo: pending = 3 for SCOPE. */
export async function seedUnsynced(scope: Scope = SCOPE, uuid = 's1'): Promise<void> {
    await db.drafts.put(draftRow(uuid, scope));
    await db.outbox.put(saveOp(uuid, scope));
    await db.attachments.put(attachmentRow(`${uuid}-photo`, { ...scope, submissionUuid: uuid }));
}

/** Cache Storage and service worker stand-ins (jsdom has neither). */
export function stubBrowserCaches() {
    const deleted: string[] = [];
    const unregister = vi.fn(() => Promise.resolve(true));
    vi.stubGlobal('caches', {
        keys: () => Promise.resolve(['workbox-precache', 'runtime']),
        delete: (key: string) => {
            deleted.push(key);
            return Promise.resolve(true);
        },
    });
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { getRegistrations: () => Promise.resolve([{ unregister }]) },
    });
    return {
        deleted,
        unregister,
        restore: () => {
            vi.unstubAllGlobals();
            Reflect.deleteProperty(navigator, 'serviceWorker');
        },
    };
}

/** navigator.storage stand-in (jsdom has none). */
export function stubStorageManager(options: { persisted?: boolean; grant?: boolean } = {}) {
    let persisted = options.persisted ?? false;
    const persist = vi.fn(() => {
        persisted = options.grant ?? true;
        return Promise.resolve(persisted);
    });
    Object.defineProperty(navigator, 'storage', {
        configurable: true,
        value: {
            persisted: () => Promise.resolve(persisted),
            persist,
            estimate: () => Promise.resolve({ usage: 12_500_000, quota: 2_000_000_000 }),
        },
    });
    return {
        persist,
        restore: () => {
            Reflect.deleteProperty(navigator, 'storage');
        },
    };
}

export function setOnline(online: boolean) {
    return vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(online);
}
