/**
 * The catalog cache (ADR 0035): forms and form versions of the current organization in IndexedDB,
 * refreshed through `/sync/manifest?since=`. Form versions are read from the cache first and the
 * network second. The forms list comes from the cache once a refresh completed (in this tab or an
 * earlier session), before that from `GET /forms`. Offline with nothing cached: NotCachedError.
 */
import { fetchForms, fetchFormVersion } from '../api/endpoints.ts';
import { ApiError, isAuthLost, NetworkError } from '../api/errors.ts';
import type { FormSummary, FormVersion } from '../api/types.ts';
import { db } from '../db/db.ts';
import type { FormRow, FormVersionRow } from '../db/schema.ts';
import { currentScope } from '../db/scope.ts';
import { purgeShortcuts } from '../forms/shortcuts.ts';
import { getOrganizationId } from '../session/org-store.ts';
import { onSyncRequested, type SyncReason } from '../sync/triggers.ts';
import { fetchFormsFor, fetchFormVersionFor } from './api.ts';
import { emitCatalogChange, onCatalogChanged } from './changes.ts';
import { readCatalogMeta, refreshCatalog, toSummary, type CatalogRefresh } from './refresh.ts';

export { onCatalogChanged };
export type { CatalogChange } from './changes.ts';
export { refreshCatalog, type CatalogRefresh };

/** Offline and the requested form version is not in the cache. */
export class NotCachedError extends Error {
    constructor(message = 'Not available offline') {
        super(message);
        this.name = 'NotCachedError';
    }
}

/** Cross-tab lock: one tab refreshes, the others skip (ADR 0035). */
export const CATALOG_LOCK = 'fsa-catalog';
/** Automatic triggers skip a refresh younger than this. */
export const CATALOG_MAX_AGE_MS = 30_000;

const inFlight = new Map<number, Promise<CatalogRefresh | null>>();

function isOffline(): boolean {
    return typeof navigator !== 'undefined' && !navigator.onLine;
}

async function withCatalogLock<T>(work: () => Promise<T>): Promise<T | null> {
    if (typeof navigator === 'undefined' || !('locks' in navigator)) {
        return work(); // In-tab only (the in-flight map below).
    }
    return navigator.locks.request(CATALOG_LOCK, { ifAvailable: true }, (lock) =>
        lock === null ? null : work(),
    );
}

/** Refreshes now unless this tab or another one already does; null when skipped. */
export function runCatalogRefresh(orgId: number): Promise<CatalogRefresh | null> {
    const running = inFlight.get(orgId);
    if (running !== undefined) {
        return running;
    }
    const run = withCatalogLock(() => refreshCatalog(orgId)).finally(() => {
        inFlight.delete(orgId);
    });
    inFlight.set(orgId, run);
    return run;
}

/** Refreshes when online and the last refresh is older than `maxAgeMs`; null when skipped. */
export async function refreshCatalogIfStale(
    orgId: number,
    maxAgeMs = CATALOG_MAX_AGE_MS,
): Promise<CatalogRefresh | null> {
    if (isOffline()) {
        return null;
    }
    const meta = await readCatalogMeta(orgId);
    if (meta !== undefined && Date.now() - meta.refreshedAt < maxAgeMs) {
        return null;
    }
    return runCatalogRefresh(orgId);
}

/** Background refreshes retry on the next trigger; only unexpected failures are logged. */
export function reportRefreshError(error: unknown): void {
    if (!(error instanceof NetworkError || error instanceof ApiError || isAuthLost(error))) {
        console.error('Catalog refresh failed', error);
    }
}

const FORCED: ReadonlySet<SyncReason> = new Set<SyncReason>(['manual', 'login']);
/** Outbox-only triggers: an enqueue (every autosave) or the outbox interval never refresh. */
const IGNORED: ReadonlySet<SyncReason> = new Set<SyncReason>(['enqueue', 'interval']);

/** Refreshes on sync requests for the signed-in user's organization; returns a stop function. */
export function startCatalogSync(): () => void {
    return onSyncRequested((reason) => {
        const scope = currentScope();
        if (scope === null || IGNORED.has(reason)) {
            return;
        }
        const run = FORCED.has(reason)
            ? runCatalogRefresh(scope.orgId)
            : refreshCatalogIfStale(scope.orgId);
        run.catch(reportRefreshError);
    });
}

export async function listCachedForms(orgId: number): Promise<readonly FormRow[]> {
    return db.forms.where('orgId').equals(orgId).toArray();
}

/** Ids of the organization's cached form versions (what can be filled offline). */
export async function listCachedVersionIds(orgId: number): Promise<readonly number[]> {
    return db.formVersions.where('orgId').equals(orgId).primaryKeys();
}

const byName = (forms: readonly FormSummary[]): FormSummary[] =>
    [...forms].sort((a, b) => a.name.localeCompare(b.name));

async function cachedSummaries(orgId: number): Promise<FormSummary[]> {
    return byName((await listCachedForms(orgId)).map(toSummary));
}

/** Organizations whose catalog finished a refresh: their forms list is served from the cache. */
const completeCatalogs = new Set<number>();
onCatalogChanged((orgId, change) => {
    if (change === 'forms') {
        completeCatalogs.add(orgId);
    }
});

/** Remembers (in the background) that an earlier session already completed the catalog. */
function noteCompleteCatalog(orgId: number): void {
    readCatalogMeta(orgId)
        .then((meta) => {
            if (meta !== undefined) {
                completeCatalogs.add(orgId);
            }
        })
        .catch(reportRefreshError);
}

/** Cached forms while the catalog is complete; null once its meta is gone (logout, wipe). */
async function completeCachedForms(orgId: number): Promise<FormSummary[] | null> {
    const [meta, forms] = await Promise.all([readCatalogMeta(orgId), cachedSummaries(orgId)]);
    if (meta === undefined) {
        completeCatalogs.delete(orgId);
        return null;
    }
    return forms;
}

/**
 * `GET /forms` lists exactly the forms the user may fill now: revoked favorites go right away, and
 * the rows are cached in the background (the list is not held back by IndexedDB).
 */
async function fetchAndStoreForms(orgId: number): Promise<FormSummary[]> {
    const forms = (await fetchFormsFor(orgId)).map(toSummary);
    purgeShortcuts(
        orgId,
        forms.map((form) => form.id),
    );
    emitCatalogChange(orgId, 'shortcuts');
    const now = Date.now();
    db.forms
        .bulkPut(forms.map((form) => ({ ...form, orgId, cachedAt: now })))
        .catch(reportRefreshError);
    return byName(forms);
}

/**
 * Forms list: the cache once the catalog is complete; otherwise `GET /forms` (stored) without
 * waiting for IndexedDB first, and offline the cached rows or NotCachedError.
 */
export async function loadForms(orgId: number | null): Promise<readonly FormSummary[]> {
    if (orgId === null) {
        return fetchForms();
    }
    const complete = completeCatalogs.has(orgId) ? await completeCachedForms(orgId) : null;
    if (complete !== null) {
        return complete;
    }
    if (!isOffline()) {
        noteCompleteCatalog(orgId);
        try {
            return await fetchAndStoreForms(orgId);
        } catch (error) {
            if (!(error instanceof NetworkError)) {
                throw error;
            }
        }
    }
    const cached = await cachedSummaries(orgId);
    if (cached.length === 0) {
        throw new NotCachedError();
    }
    return cached;
}

function stripRow(row: FormVersionRow): FormVersion {
    return {
        id: row.id,
        form_id: row.form_id,
        version: row.version,
        checksum: row.checksum,
        definition: row.definition,
        design: row.design,
        published_at: row.published_at,
        retired: row.retired,
    };
}

/** Cache first, then network (stored on success); offline and missing → NotCachedError. */
export async function getFormVersion(
    orgId: number,
    formId: number,
    versionId: number,
): Promise<FormVersion> {
    const cached = await db.formVersions.get(versionId);
    if (cached !== undefined && cached.orgId === orgId && cached.form_id === formId) {
        return stripRow(cached);
    }
    if (isOffline()) {
        throw new NotCachedError();
    }
    try {
        const version = await fetchFormVersionFor(orgId, formId, versionId);
        await db.formVersions.put({ ...version, orgId, cachedAt: Date.now() });
        return version;
    } catch (error) {
        throw error instanceof NetworkError ? new NotCachedError() : error;
    }
}

/** Form version for the current organization (network only without one). */
export function loadFormVersion(formId: number, versionId: number): Promise<FormVersion> {
    const orgId = getOrganizationId();
    return orgId === null
        ? fetchFormVersion(formId, versionId)
        : getFormVersion(orgId, formId, versionId);
}
