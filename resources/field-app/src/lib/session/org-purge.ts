/**
 * Local data of organizations the user no longer belongs to (ADR 0035): their drafts, outbox ops and
 * attachments, plus the organization's catalog cache. Rows of other users stay (QUESTIONS #15).
 */
import type { Me } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { Scope } from '../db/schema.ts';

const CATALOG_PREFIX = 'catalog:';

/** Removes one user's drafts, outbox ops and attachments of one organization, plus its catalog cache. */
export async function purgeOrganizationRows(scope: Scope): Promise<void> {
    const key: [number, number] = [scope.orgId, scope.userId];
    await db.transaction(
        'rw',
        [db.drafts, db.outbox, db.attachments, db.forms, db.formVersions, db.meta],
        async () => {
            await db.drafts.where('[orgId+userId]').equals(key).delete();
            await db.outbox.where('[orgId+userId]').equals(key).delete();
            await db.attachments
                .filter((row) => row.orgId === scope.orgId && row.userId === scope.userId)
                .delete();
            await db.forms.where('orgId').equals(scope.orgId).delete();
            await db.formVersions.where('orgId').equals(scope.orgId).delete();
            await db.meta.delete(`${CATALOG_PREFIX}${String(scope.orgId)}` as `catalog:${number}`);
        },
    );
}

/**
 * Organizations with drafts or outbox ops of `userId` or with a catalog cache on this device. Read
 * from the indexes only (no row values); attachments are left out, since loading them would read
 * their bytes, and a stored attachment always belongs to a draft (logout and 403 purge the rest).
 */
async function localOrganizationIds(userId: number): Promise<Set<number>> {
    const [drafts, ops, forms, versions, metaKeys] = await Promise.all([
        db.drafts.orderBy('[orgId+userId]').uniqueKeys(),
        db.outbox.orderBy('[orgId+userId]').uniqueKeys(),
        db.forms.orderBy('orgId').uniqueKeys(),
        db.formVersions.orderBy('orgId').uniqueKeys(),
        db.meta.toCollection().primaryKeys(),
    ]);
    const mine = [...drafts, ...ops]
        .map((key) => key as unknown as readonly [number, number])
        .filter(([, owner]) => owner === userId)
        .map(([orgId]) => orgId);
    const catalogs = metaKeys
        .map(String)
        .filter((key) => key.startsWith(CATALOG_PREFIX))
        .map((key) => Number(key.slice(CATALOG_PREFIX.length)));
    return new Set([...mine, ...[...forms, ...versions].map(Number), ...catalogs]);
}

/**
 * After every fresh `/me`: purge what this user keeps of organizations missing from it (membership
 * deactivated while the app was closed or offline). Returns the purged organization ids.
 */
export async function purgeRevokedOrganizations(me: Me): Promise<number[]> {
    const members = new Set(me.organizations.map((organization) => organization.id));
    const gone = [...(await localOrganizationIds(me.user.id))].filter(
        (orgId) => Number.isInteger(orgId) && !members.has(orgId),
    );
    for (const orgId of gone) {
        await purgeOrganizationRows({ orgId, userId: me.user.id });
    }
    return gone;
}
