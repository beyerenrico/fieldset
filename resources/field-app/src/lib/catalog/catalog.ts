/** The catalog cache (ADR 0035, agent B). Stub delegating to the network until M6-T06 lands. */
import { fetchFormVersion } from '../api/endpoints.ts';
import type { FormVersion } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { FormRow } from '../db/schema.ts';

/** Offline and the requested form version is not in the cache. */
export class NotCachedError extends Error {
    constructor(message = 'Not available offline') {
        super(message);
        this.name = 'NotCachedError';
    }
}

export interface CatalogRefresh {
    readonly cursor: string;
    readonly upserted: number;
    readonly fetchedVersions: number;
    readonly purgedFormIds: readonly number[];
}

/** Refreshes on sync requests (cross-tab lock 'fsa-catalog') and returns a stop function. */
export function startCatalogSync(): () => void {
    return () => undefined;
}

export const refreshCatalog: (orgId: number) => Promise<CatalogRefresh> = () =>
    Promise.reject(new Error('refreshCatalog is not implemented yet (M6-T06)'));

export async function listCachedForms(orgId: number): Promise<readonly FormRow[]> {
    return db.forms.where('orgId').equals(orgId).toArray();
}

/** Cache first, then network (stored on success); offline and missing → NotCachedError. */
export const getFormVersion: (
    orgId: number,
    formId: number,
    versionId: number,
) => Promise<FormVersion> = (_orgId, formId, versionId) => fetchFormVersion(formId, versionId);
