/**
 * Catalog requests for one explicit organization. The header is set per request (ADR 0035), so a
 * refresh that started before an organization switch never writes the wrong organization's forms.
 */
import { apiRequest } from '../api/client.ts';
import { paths } from '../api/paths.ts';
import type { FormSummary, FormVersion, SyncManifest } from '../api/types.ts';

const forOrganization = (orgId: number) => ({
    headers: { 'X-Organization-Id': String(orgId) },
});

/** `GET /sync/manifest?since=` (inclusive cursor; omitted = everything). */
export async function fetchManifestFor(orgId: number, since?: string): Promise<SyncManifest> {
    const query = since === undefined ? {} : { since };
    return (
        await apiRequest<SyncManifest>(paths.syncManifest, { query, ...forOrganization(orgId) })
    ).data;
}

export async function fetchFormsFor(orgId: number): Promise<readonly FormSummary[]> {
    return (await apiRequest<readonly FormSummary[]>(paths.forms, forOrganization(orgId))).data;
}

export async function fetchFormVersionFor(
    orgId: number,
    formId: number,
    versionId: number,
): Promise<FormVersion> {
    const path = paths.formVersion(formId, versionId);
    return (await apiRequest<FormVersion>(path, forOrganization(orgId))).data;
}
