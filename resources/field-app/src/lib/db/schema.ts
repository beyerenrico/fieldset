/** Row types of the offline store (ADR 0035). A shape change needs a new Dexie version in db.ts. */
import type { JsonObject } from '@fieldset/fieldset-js';
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
    /** Attachment uuids of a background submit refused with 422 `attachments_missing`. */
    readonly missingAttachments?: readonly string[];
    /**
     * PUTs sent on the current base revision whose answer has not arrived (lost responses): a later
     * 409 whose server copy is one of them is adopted instead of opening a conflict.
     */
    readonly sentSaves?: readonly SentSave[];
}

/** One PUT as it left the device: base revision, local version and a stable hash of its data. */
export interface SentSave {
    readonly revision: number;
    readonly version: number;
    readonly hash: string;
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
    /** `${kind}:${userId}:${submissionUuid}` (+ `:${attachmentUuid}`): enqueueing again coalesces. */
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
          /** Local attachments were queued again once after 422 `attachments_missing`. */
          readonly reuploaded?: boolean;
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
    /** The server ended this session (reauth): an offline start shows the login page instead. */
    readonly reauth?: boolean;
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
    /** The user whose manifest filled this cache; another user's catalog is purged, not served. */
    readonly userId?: number;
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
