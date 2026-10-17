/**
 * 403 `organization_forbidden` (ADR 0035, docs/api/field-app-v1.md): the membership may have been
 * deactivated. Refetch `/me`; when the organization is gone, purge this user's rows of it; when no
 * organization is left, ask `device-status` and follow its answer (`wipe` → clear everything).
 * Normally the server ended the sessions as well, so a 401 → device-status comes first. The UI
 * (App.tsx) re-resolves the selected organization when the refreshed session arrives.
 */
import { fetchDeviceStatus } from '../api/device-status.ts';
import { fetchMe } from '../api/endpoints.ts';
import { DeviceWipedError } from '../api/errors.ts';
import { db } from '../db/db.ts';
import type { Scope } from '../db/schema.ts';
import { wipeDevice } from './account.ts';
import { reportAuthError } from './auth-events.ts';
import { adoptSession } from './offline-start.ts';

export type OrganizationAccess = 'member' | 'purged' | 'wiped';

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
            await db.meta.delete(`catalog:${String(scope.orgId)}` as `catalog:${number}`);
        },
    );
}

const running = new Map<number, Promise<OrganizationAccess>>();

async function check(orgId: number): Promise<OrganizationAccess> {
    const me = await fetchMe();
    await adoptSession(me);
    if (me.organizations.some((organization) => organization.id === orgId)) {
        return 'member'; // e.g. a role change: nothing to purge.
    }
    await purgeOrganizationRows({ orgId, userId: me.user.id });
    if (me.organizations.length === 0 && (await fetchDeviceStatus()) === 'wipe') {
        await wipeDevice();
        reportAuthError(new DeviceWipedError('This device was signed out and its data removed.'));
        return 'wiped';
    }
    return 'purged';
}

/** Concurrent 403s of one organization share one check. Network and auth errors propagate. */
export function handleOrganizationForbidden(orgId: number): Promise<OrganizationAccess> {
    const existing = running.get(orgId);
    if (existing !== undefined) {
        return existing;
    }
    const next = check(orgId).finally(() => {
        running.delete(orgId);
    });
    running.set(orgId, next);
    return next;
}
