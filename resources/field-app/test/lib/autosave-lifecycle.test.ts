/**
 * Autosave lifecycle: stop/dispose and settling (fake timers), then the local transport against
 * the real store (fake-indexeddb): DraftRow writes, coalesced ops, and the killed-tab AC (§9.2).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AutosaveController } from '../../src/lib/autosave/controller.ts';
import {
    SessionEndedError,
    StorageFullError,
    isQuotaExceeded,
} from '../../src/lib/autosave/errors.ts';
import { createLocalTransport } from '../../src/lib/autosave/local-transport.ts';
import type { SaveReceipt, SaveRequest } from '../../src/lib/autosave/types.ts';
import { db } from '../../src/lib/db/db.ts';
import { setCurrentUserId } from '../../src/lib/db/scope.ts';
import { chooseStart, readLocalDraft } from '../../src/lib/drafts/local-drafts.ts';
import { opId } from '../../src/lib/outbox/outbox.ts';
import { SCOPE, draftRow, outboxIds, setupSyncScope, signInSession } from '../utils/a-store.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { setupTestServer } from '../utils/server.ts';

setupTestServer();
setupSyncScope();

type Save = (request: SaveRequest) => Promise<SaveReceipt>;

function controller(
    save: Save,
    extra: Partial<ConstructorParameters<typeof AutosaveController>[0]> = {},
) {
    return new AutosaveController({
        uuid: 'u1',
        formVersionId: 7,
        data: {},
        transport: { save },
        retryMs: 1000,
        ...extra,
    });
}

const ok: Save = () => Promise.resolve({ localVersion: 1 });

describe('AutosaveController: stop and dispose', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('stop() writes once more, then ignores edits and never writes again', async () => {
        const save = vi.fn(ok);
        const autosave = controller(save);
        autosave.update({ a: 1 });
        await autosave.stop();
        expect(save).toHaveBeenCalledOnce();
        autosave.update({ a: 2 });
        await vi.advanceTimersByTimeAsync(10_000);
        expect(await autosave.flush()).toMatchObject({ state: 'saved' });
        expect(save).toHaveBeenCalledOnce();
        expect(autosave.data).toEqual({ a: 1 });
    });

    it('does not bring retry timers back after stop()', async () => {
        const save = vi.fn(() => Promise.reject(new Error('quota')));
        const autosave = controller(save);
        autosave.update({ a: 1 });
        await vi.advanceTimersByTimeAsync(300);
        expect(save).toHaveBeenCalledOnce();
        await autosave.stop();
        expect(save).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(save).toHaveBeenCalledTimes(2);
        expect(autosave.busy()).toBe(false);
    });

    it('dispose() drops pending changes without writing', async () => {
        const save = vi.fn(ok);
        const autosave = controller(save);
        const listener = vi.fn();
        autosave.subscribe(listener);
        autosave.update({ a: 1 });
        autosave.dispose();
        await vi.advanceTimersByTimeAsync(10_000);
        expect(save).not.toHaveBeenCalled();
        expect(listener).toHaveBeenCalled();
    });

    it('settles once a retried write went through', async () => {
        const save = vi.fn<Save>().mockRejectedValueOnce(new Error('quota')).mockImplementation(ok);
        const autosave = controller(save);
        autosave.update({ a: 1 });
        const settled = vi.fn();
        void autosave.settled().then(settled);
        await vi.advanceTimersByTimeAsync(300);
        expect(settled).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1000);
        expect(settled).toHaveBeenCalledOnce();
        expect(autosave.getSnapshot().state).toBe('saved');
    });
});

const UUID = '0192f1c4-0000-7000-8000-00000000f001';

function localTransport(server = null as ReturnType<typeof makeSubmission> | null) {
    return createLocalTransport({ scope: SCOPE, uuid: UUID, formId: 3, formVersionId: 7, server });
}

describe('local transport', () => {
    it('writes the DraftRow (localVersion + 1) and coalesces one draft.save op', async () => {
        const transport = localTransport();
        await transport.save({ uuid: UUID, formVersionId: 7, data: { a: 1 } });
        await transport.save({ uuid: UUID, formVersionId: 7, data: { a: 2 } });
        expect(await db.drafts.get(UUID)).toMatchObject({
            ...SCOPE,
            data: { a: 2 },
            localVersion: 2,
            syncedVersion: 0,
            serverRevision: 0,
            sync: 'queued',
        });
        expect(await outboxIds()).toEqual([opId('draft.save', SCOPE.userId, UUID)]);
    });

    it('starts a server draft from its revision; ensureOnly keeps it clean', async () => {
        const server = makeSubmission({ uuid: UUID, revision: 4, data: { a: 'server' } });
        const transport = localTransport(server);
        await transport.save({
            uuid: UUID,
            formVersionId: 7,
            data: { a: 'server' },
            ensureOnly: true,
        });
        expect(await db.drafts.get(UUID)).toMatchObject({
            localVersion: 1,
            syncedVersion: 1,
            serverRevision: 4,
            sync: 'synced',
        });
        expect(await outboxIds()).toEqual([]);
        await transport.save({ uuid: UUID, formVersionId: 7, data: { a: 'edit' } });
        expect(await db.drafts.get(UUID)).toMatchObject({ localVersion: 2, serverRevision: 4 });
        expect(await outboxIds()).toEqual([opId('draft.save', SCOPE.userId, UUID)]);
    });

    it('rebases a clean row behind a newer server copy; a paused row stays paused', async () => {
        await db.drafts.put(
            draftRow({ uuid: UUID, localVersion: 2, syncedVersion: 2, serverRevision: 1 }),
        );
        const server = makeSubmission({ uuid: UUID, revision: 3 });
        await localTransport(server).save({ uuid: UUID, formVersionId: 7, data: { a: 1 } });
        expect(await db.drafts.get(UUID)).toMatchObject({ serverRevision: 3, localVersion: 3 });

        await db.drafts.put(
            draftRow({
                uuid: UUID,
                sync: 'conflict',
                submitErrors: [{ pointer: '/a', code: 'required' }],
            }),
        );
        await localTransport().save({ uuid: UUID, formVersionId: 7, data: { a: 2 } });
        expect(await db.drafts.get(UUID)).toMatchObject({ sync: 'conflict', submitErrors: [] });
    });
});

describe('local transport guards', () => {
    it('refuses writes once the session ended or another user signed in elsewhere', async () => {
        const transport = localTransport();
        await db.meta.delete('session'); // logout or wipe in another tab
        await expect(
            transport.save({ uuid: UUID, formVersionId: 7, data: { a: 1 } }),
        ).rejects.toMatchObject({
            code: 'session_ended',
        });
        await signInSession(8); // another user signed in in another tab
        await expect(
            transport.save({ uuid: UUID, formVersionId: 7, data: { a: 2 } }),
        ).rejects.toBeInstanceOf(SessionEndedError);
        await signInSession(SCOPE.userId);
        setCurrentUserId(null); // this tab signed out
        await expect(
            transport.save({ uuid: UUID, formVersionId: 7, data: { a: 3 } }),
        ).rejects.toBeInstanceOf(SessionEndedError);
        expect(await db.drafts.count()).toBe(0);
        expect(await db.outbox.count()).toBe(0);
    });

    it('reports a full storage (also Dexie-wrapped) as storage_full', async () => {
        const quota = Object.assign(new Error('Quota exceeded'), { name: 'QuotaExceededError' });
        vi.spyOn(db.drafts, 'put').mockRejectedValueOnce(quota);
        const failure: unknown = await localTransport()
            .save({ uuid: UUID, formVersionId: 7, data: { a: 1 } })
            .catch((error: unknown) => error);
        expect(failure).toBeInstanceOf(StorageFullError);
        expect(failure).toMatchObject({ code: 'storage_full' });
        const wrapped = Object.assign(new Error('Transaction aborted'), {
            name: 'AbortError',
            inner: quota,
        });
        expect(isQuotaExceeded(wrapped)).toBe(true);
        expect(isQuotaExceeded(new Error('other'))).toBe(false);
    });
});

describe('killed tab (§9.2 AC)', () => {
    it('writes the row on pagehide; a new controller starts from it', async () => {
        const first = new AutosaveController({
            uuid: UUID,
            formVersionId: 7,
            data: {},
            transport: localTransport(),
        });
        first.start();
        first.update({ customer: 'ACME', items: [{ name: 'Pump' }] });
        window.dispatchEvent(new Event('pagehide'));
        // The tab is killed here: no timer, no unmount, no stop() runs any more.
        await vi.waitFor(async () => {
            expect(await db.drafts.get(UUID)).toMatchObject({ localVersion: 1 });
        });

        // Reopening (new controller, server copy unreachable): the local row is the start.
        const row = await readLocalDraft(UUID, SCOPE);
        const start = chooseStart(row, null);
        expect(start?.data).toEqual({ customer: 'ACME', items: [{ name: 'Pump' }] });
        const second = new AutosaveController({
            uuid: UUID,
            formVersionId: start?.formVersionId ?? 0,
            data: start?.data ?? {},
            transport: localTransport(),
            localVersion: row?.localVersion ?? 0,
        });
        expect(second.data).toEqual({ customer: 'ACME', items: [{ name: 'Pump' }] });
        expect(second.getSnapshot().localVersion).toBe(1);
        first.dispose();
    });

    it('prefers the local row only while it is newer than the server copy', () => {
        const server = makeSubmission({ uuid: UUID, revision: 5, data: { a: 'server' } });
        const dirty = draftRow({
            uuid: UUID,
            data: { a: 'mine' },
            localVersion: 3,
            syncedVersion: 2,
            serverRevision: 4,
        });
        expect(chooseStart(dirty, server)?.data).toEqual({ a: 'mine' });
        const clean = { ...dirty, syncedVersion: 3 };
        expect(chooseStart(clean, server)?.data).toEqual({ a: 'server' });
        expect(chooseStart({ ...clean, serverRevision: 5 }, server)?.data).toEqual({ a: 'mine' });
        expect(chooseStart(null, null, { formId: 3, versionId: 7 })).toMatchObject({
            formId: 3,
            formVersionId: 7,
            data: {},
        });
        expect(chooseStart(null, null)).toBeNull();
    });
});
