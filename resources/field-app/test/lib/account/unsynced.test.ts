/** Unsynced work and other users' rows (ADR 0035, QUESTIONS #15). */
import { describe, expect, it } from 'vitest';
import { db } from '../../../src/lib/db/db.ts';
import {
    countForeignRows,
    deleteForeignRows,
    unsyncedForUser,
} from '../../../src/lib/session/unsynced.ts';
import { draftRow, saveOp, seedUnsynced } from '../../utils/c-rows.ts';
import { setupTestServer } from '../../utils/server.ts';

setupTestServer();

describe('unsynced work', () => {
    it('sums every organization of the user and ignores other users', async () => {
        await seedUnsynced({ orgId: 1, userId: 7 }, 'a');
        await seedUnsynced({ orgId: 2, userId: 7 }, 'b');
        await seedUnsynced({ orgId: 1, userId: 99 }, 'c');
        await db.drafts.put(draftRow('synced', { localVersion: 3, syncedVersion: 3 }));
        await db.drafts.put(
            draftRow('conflict', { sync: 'conflict', localVersion: 1, syncedVersion: 1 }),
        );
        await db.outbox.put(saveOp('blocked', { state: 'blocked' }));

        expect(await unsyncedForUser(7, [1, 2, 2])).toEqual({
            submissions: 4, // a, b, conflict, blocked
            ops: 3,
            dirtyDrafts: 2,
            pendingAttachments: 2,
            blocked: 2,
            pending: 7,
        });
        expect(await unsyncedForUser(7, [])).toMatchObject({
            submissions: 0,
            pending: 0,
            blocked: 0,
        });
    });

    it('counts and deletes the rows of other users only', async () => {
        await seedUnsynced({ orgId: 1, userId: 7 }, 'mine');
        await seedUnsynced({ orgId: 1, userId: 99 }, 'theirs');
        await seedUnsynced({ orgId: 3, userId: 42 }, 'others');

        expect(await countForeignRows(7)).toBe(6);
        await deleteForeignRows(7);
        expect(await countForeignRows(7)).toBe(0);
        expect(await db.drafts.toCollection().primaryKeys()).toEqual(['mine']);
        expect(await db.outbox.count()).toBe(1);
        expect(await db.attachments.count()).toBe(1);
    });
});
