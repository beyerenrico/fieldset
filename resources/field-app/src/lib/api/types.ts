/** Response shapes of the Field App API (docs/api/field-app-v1.md). */
import type { FormDefinition, JsonObject } from '@form-schema-app/form-schema-js';

export interface User {
    readonly id: number;
    readonly name: string;
    readonly email: string;
    readonly locale: string | null;
    readonly timezone: string | null;
    readonly email_verified: boolean;
    readonly mfa_enabled: boolean;
}

export interface Group {
    readonly id: number;
    readonly name: string;
}

export interface Organization {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
    readonly locale: string | null;
    readonly timezone: string | null;
    readonly branding: Readonly<Record<string, unknown>> | null;
    readonly roles: readonly string[];
    readonly permissions: readonly string[];
    readonly groups: readonly Group[];
}

export interface Me {
    readonly user: User;
    readonly organizations: readonly Organization[];
}

export interface LoginResult {
    readonly two_factor: boolean;
}

export interface DeviceRegistration {
    readonly device_uuid: string;
    readonly secret: string;
}

export interface FormVersionRef {
    readonly id: number;
    readonly version: number;
    readonly checksum: string;
}

export interface FormSummary {
    readonly id: number;
    readonly key: string;
    readonly name: string;
    readonly category: string | null;
    readonly version: FormVersionRef;
}

export interface FormVersion {
    readonly id: number;
    readonly form_id: number;
    readonly version: number;
    readonly checksum: string;
    readonly definition: FormDefinition;
    readonly design: unknown;
    readonly published_at: string | null;
    readonly retired: boolean;
}

export interface SyncManifest {
    readonly cursor: string;
    readonly forms: readonly FormSummary[];
    /** Complete list of forms the user may fill now; purge everything else. */
    readonly fillable_form_ids: readonly number[];
    /** Best-effort list of revoked forms. */
    readonly revoked_form_ids: readonly number[];
    readonly tasks: readonly unknown[];
}

export type SubmissionStatus =
    'draft' | 'submitted' | 'in_review' | 'changes_requested' | 'approved' | 'completed' | 'void';

export const SUBMISSION_STATUSES: readonly SubmissionStatus[] = [
    'draft',
    'submitted',
    'in_review',
    'changes_requested',
    'approved',
    'completed',
    'void',
];

export type AttachmentKind = 'photo' | 'file' | 'signature';

export interface AttachmentRecord {
    readonly uuid: string;
    readonly kind: AttachmentKind;
    readonly element_key: string;
    readonly mime: string;
    readonly size: number;
    readonly sha256?: string;
    readonly url: string;
}

export interface Submission {
    readonly uuid: string;
    readonly form_id: number;
    readonly form_version_id: number;
    readonly version: number;
    readonly status: SubmissionStatus;
    readonly revision: number;
    readonly number: string | null;
    readonly data: JsonObject;
    readonly attachments: readonly AttachmentRecord[];
    readonly submitted_at: string | null;
    readonly created_at: string;
    readonly updated_at: string;
    readonly can_edit: boolean;
}

export interface PaginationMeta {
    readonly current_page: number;
    readonly last_page: number;
    readonly total: number;
}

export interface Page<T> {
    readonly items: readonly T[];
    readonly meta: PaginationMeta;
}

export const EDITABLE_STATUSES: readonly SubmissionStatus[] = ['draft', 'changes_requested'];
