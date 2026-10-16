/** Row types of the offline store (ADR 0035). A shape change needs a new Dexie version in db.ts. */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import type { SendInput } from '../api/endpoints.ts';
import type { ValidationDetail } from '../api/errors.ts';
import type {
    AttachmentKind,
    AttachmentRecord,
    FormSummary,
    FormVersion,
    Me,
    Submission,
} from '../api/types.ts';

/** Every row belongs to one organization and one user; reads filter by both. */
export interface Scope {
    readonly orgId: number;
    readonly userId: number;
}

export interface DeviceRecord {
    readonly deviceUuid: string;
    /** `null` when the server no longer knows the secret (re-register after the next login). */
    readonly secret: string | null;
}

export type DraftSync = 'local' | 'queued' | 'syncing' | 'synced' | 'conflict' | 'blocked';

export interface DraftRow extends Scope {
    readonly uuid: string;
    readonly formId: number;
    readonly formVersionId: number;
    readonly data: JsonObject;
    /** +1 on every local write; dirty while localVersion > syncedVersion. */
    readonly localVersion: number;
    readonly syncedVersion: number;
    /** Revision the next PUT is based on; 0 = not on the server yet. */
    readonly serverRevision: number;
    /** Last server copy (status, number, attachments, can_edit). */
    readonly server: Submission | null;
    /** Server copy of a revision_conflict until the user decides. */
    readonly conflict: Submission | null;
    readonly sync: DraftSync;
    /** Error code blocking this submission (not_editable, version_retired, form_access_revoked, …). */
    readonly blockedBy: string | null;
    /** Pointer errors of the last refused submit (survive reloads). */
    readonly submitErrors: readonly ValidationDetail[];
    readonly createdAt: number;
    readonly updatedAt: number;
}

export interface OutboxError {
    readonly status: number | null;
    readonly code: string;
    readonly message: string;
}

/** Exact retry identity: one Idempotency-Key always travels with one body. */
export interface PersistedRequest {
    readonly idempotencyKey: string;
    readonly body: JsonObject;
}

interface OutboxBase extends Scope {
    /** `${kind}:${submissionUuid}` (+ `:${attachmentUuid}`): enqueueing again coalesces. */
    readonly id: string;
    readonly submissionUuid: string;
    /** Global enqueue order (nextSeq). */
    readonly seq: number;
    readonly state: 'pending' | 'blocked';
    readonly attempts: number;
    /** Epoch ms; a manual sync ignores it. */
    readonly nextAttemptAt: number;
    readonly lastError: OutboxError | null;
    readonly createdAt: number;
}

export type OutboxRow =
    | (OutboxBase & { readonly kind: 'draft.save' })
    | (OutboxBase & { readonly kind: 'attachment.upload'; readonly attachmentUuid: string })
    | (OutboxBase & {
          readonly kind: 'submit';
          readonly clientSubmittedAt: string;
          readonly request: PersistedRequest | null;
      })
    | (OutboxBase & {
          readonly kind: 'send';
          readonly input: SendInput;
          readonly request: PersistedRequest;
      });

export type OutboxKind = OutboxRow['kind'];

export interface AttachmentRow extends Scope {
    readonly uuid: string;
    readonly submissionUuid: string;
    readonly kind: AttachmentKind;
    readonly elementKey: string;
    readonly fileName: string;
    readonly mime: string;
    readonly size: number;
    /** ArrayBuffer, not Blob (older Safari, fake-indexeddb under jsdom); null once pruned after submit. */
    readonly bytes: ArrayBuffer | null;
    readonly state: 'pending' | 'uploaded' | 'failed';
    readonly error: OutboxError | null;
    readonly record: AttachmentRecord | null;
    readonly createdAt: number;
}

export interface FormRow extends FormSummary {
    readonly orgId: number;
    readonly cachedAt: number;
}

export interface FormVersionRow extends FormVersion {
    readonly orgId: number;
    readonly cachedAt: number;
}

export interface SessionMeta {
    readonly userId: number;
    readonly me: Me;
    readonly savedAt: number;
}

export type SyncState = 'idle' | 'running' | 'offline' | 'reauth' | 'error';

export interface SyncMeta {
    readonly state: SyncState;
    readonly lastRunAt: number | null;
    readonly lastSuccessAt: number | null;
    readonly lastError: OutboxError | null;
}

export interface CatalogMeta {
    readonly cursor: string;
    readonly refreshedAt: number;
}

export interface MetaValues {
    device: DeviceRecord;
    session: SessionMeta;
    sync: SyncMeta;
    seq: number;
    [key: `catalog:${number}`]: CatalogMeta;
}

export type MetaKey = keyof MetaValues;

export interface MetaRow {
    readonly key: MetaKey;
    readonly value: unknown;
}
