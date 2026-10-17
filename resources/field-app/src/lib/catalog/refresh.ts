/**
 * One catalog refresh (ADR 0035): manifest since the stored cursor → summaries (upsert by id; one
 * `GET /forms` when a fillable form is unknown) → missing or changed versions → one transaction that
 * stores forms and versions, marks retired versions, purges what is no longer fillable and only then
 * saves the cursor.
 * Any failed fetch leaves the store untouched, so the next run repeats from the old cursor.
 */
import { isApiError } from '../api/errors.ts';
import type { FormSummary, FormVersion } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { CatalogMeta, FormRow } from '../db/schema.ts';
import { purgeShortcuts } from '../forms/shortcuts.ts';
import { fetchFormsFor, fetchFormVersionFor, fetchManifestFor } from './api.ts';
import { emitCatalogChange } from './changes.ts';

export interface CatalogRefresh {
    readonly cursor: string;
    readonly upserted: number;
    readonly fetchedVersions: number;
    readonly purgedFormIds: readonly number[];
}

/** Parallel version downloads per run. */
const VERSION_CONCURRENCY = 4;

export const catalogKey = (orgId: number) => `catalog:${String(orgId)}` as `catalog:${number}`;

export async function readCatalogMeta(orgId: number): Promise<CatalogMeta | undefined> {
    return (await db.meta.get(catalogKey(orgId)))?.value as CatalogMeta | undefined;
}

export function toSummary(row: FormSummary): FormSummary {
    return {
        id: row.id,
        key: row.key,
        name: row.name,
        category: row.category,
        version: row.version,
    };
}

/** Summaries of every fillable form: cached rows, overridden by the manifest, then by GET /forms. */
async function collectSummaries(
    orgId: number,
    manifestForms: readonly FormSummary[],
    fillable: ReadonlySet<number>,
): Promise<Map<number, FormSummary>> {
    const cached = await db.forms.where('orgId').equals(orgId).toArray();
    const summaries = new Map<number, FormSummary>(cached.map((row) => [row.id, toSummary(row)]));
    for (const form of manifestForms) {
        summaries.set(form.id, toSummary(form));
    }
    if ([...fillable].some((id) => !summaries.has(id))) {
        // Role changes touch no form, so the manifest cannot list them: one GET /forms per run.
        for (const form of await fetchFormsFor(orgId)) {
            summaries.set(form.id, toSummary(form));
        }
    }
    return new Map([...summaries].filter(([id]) => fillable.has(id)));
}

async function needsVersion(orgId: number, summary: FormSummary): Promise<boolean> {
    const cached = await db.formVersions.get(summary.version.id);
    return (
        cached === undefined ||
        cached.orgId !== orgId ||
        cached.form_id !== summary.id ||
        cached.checksum !== summary.version.checksum
    );
}

interface VersionDownloads {
    readonly fetched: readonly FormVersion[];
    /** Forms whose version answered 403/404 (revoked meanwhile): not stored, retried next run. */
    readonly unavailable: ReadonlySet<number>;
}

async function downloadVersions(
    orgId: number,
    summaries: readonly FormSummary[],
): Promise<VersionDownloads> {
    const queue: FormSummary[] = [];
    for (const summary of summaries) {
        if (await needsVersion(orgId, summary)) {
            queue.push(summary);
        }
    }
    const fetched: FormVersion[] = [];
    const unavailable = new Set<number>();
    const worker = async (): Promise<void> => {
        for (let next = queue.shift(); next !== undefined; next = queue.shift()) {
            try {
                fetched.push(await fetchFormVersionFor(orgId, next.id, next.version.id));
            } catch (error) {
                if (!isApiError(error, 403) && !isApiError(error, 404)) {
                    throw error;
                }
                unavailable.add(next.id);
            }
        }
    };
    const lanes = Math.min(VERSION_CONCURRENCY, queue.length);
    await Promise.all(Array.from({ length: lanes }, worker));
    return { fetched, unavailable };
}

/** Deletes versions of the organization that no cached form points to and no local draft uses. */
async function purgeVersions(orgId: number, forms: readonly FormRow[]): Promise<void> {
    const keep = new Set(forms.map((form) => form.version.id));
    for (const draft of await db.drafts.toArray()) {
        if (draft.orgId === orgId) {
            keep.add(draft.formVersionId);
        }
    }
    const ids = await db.formVersions.where('orgId').equals(orgId).primaryKeys();
    await db.formVersions.bulkDelete(ids.filter((id) => !keep.has(id)));
}

/**
 * Retiring changes no form row, so the manifest lists retired version ids instead: cached copies
 * (kept while a local draft uses them) say `retired`, and the sync engine blocks those drafts.
 */
async function markRetired(orgId: number, retired: ReadonlySet<number>): Promise<void> {
    const rows = await db.formVersions.where('orgId').equals(orgId).toArray();
    const changed = rows.filter((row) => retired.has(row.id) && !row.retired);
    await db.formVersions.bulkPut(changed.map((row) => ({ ...row, retired: true })));
}

interface CatalogWrite {
    readonly cursor: string;
    readonly forms: readonly FormSummary[];
    readonly versions: readonly FormVersion[];
    readonly fillable: ReadonlySet<number>;
    readonly retired: ReadonlySet<number>;
}

async function writeCatalog(orgId: number, write: CatalogWrite): Promise<number[]> {
    const now = Date.now();
    return db.transaction('rw', [db.forms, db.formVersions, db.drafts, db.meta], async () => {
        await db.formVersions.bulkPut(write.versions.map((v) => ({ ...v, orgId, cachedAt: now })));
        await markRetired(orgId, write.retired);
        await db.forms.bulkPut(write.forms.map((form) => ({ ...form, orgId, cachedAt: now })));
        const rows = await db.forms.where('orgId').equals(orgId).toArray();
        const purged = rows.filter((row) => !write.fillable.has(row.id));
        await db.forms.bulkDelete(purged.map((row): [number, number] => [orgId, row.id]));
        await purgeVersions(
            orgId,
            rows.filter((row) => write.fillable.has(row.id)),
        );
        const meta: CatalogMeta = { cursor: write.cursor, refreshedAt: now };
        await db.meta.put({ key: catalogKey(orgId), value: meta });
        return purged.map((row) => row.id);
    });
}

/** Runs one refresh for `orgId`; rejects (store untouched) when any request fails. */
export async function refreshCatalog(orgId: number): Promise<CatalogRefresh> {
    const manifest = await fetchManifestFor(orgId, (await readCatalogMeta(orgId))?.cursor);
    const fillable = new Set(manifest.fillable_form_ids);
    // Favorites/recent need no download: drop revoked forms right away.
    purgeShortcuts(orgId, manifest.fillable_form_ids);
    emitCatalogChange(orgId, 'shortcuts');
    const summaries = [...(await collectSummaries(orgId, manifest.forms, fillable)).values()];
    const { fetched, unavailable } = await downloadVersions(orgId, summaries);
    const forms = summaries.filter((summary) => !unavailable.has(summary.id));
    const purgedFormIds = await writeCatalog(orgId, {
        cursor: manifest.cursor,
        forms,
        versions: fetched,
        fillable,
        retired: new Set(manifest.retired_version_ids),
    });
    emitCatalogChange(orgId, 'forms');
    return {
        cursor: manifest.cursor,
        upserted: forms.length,
        fetchedVersions: fetched.length,
        purgedFormIds,
    };
}
