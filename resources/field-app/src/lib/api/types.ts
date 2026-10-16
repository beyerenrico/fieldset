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
    /** Open review tasks of the user (same shape as `GET /tasks`). */
    readonly tasks: readonly Task[];
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
    /** `POST /submissions/{uuid}/send` would be accepted for this user now. */
    readonly can_send: boolean;
    /** Latest review decision (null before any; absent on servers before M4). */
    readonly review?: ReviewDecision | null;
    /** Open review task of this submission that the current user may complete, else null. */
    readonly open_task_id?: number | null;
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

/** One rendered PDF of a submission (`GET /submissions/{uuid}/document`). */
export interface Rendition {
    readonly uuid: string;
    readonly form_version_id: number;
    readonly design_version_id: number | null;
    readonly renderer_version: string;
    readonly page_count: number;
    readonly size: number;
    readonly file_sha256: string;
    readonly input_hash: string;
    readonly created_at: string;
}

export interface SubmissionDocument {
    /** Signed download link, usable without the session until `expires_at`. */
    readonly url: string;
    readonly expires_at: string;
    readonly rendition: Rendition;
}

/** `waiting_for_document`: the mail goes out once the PDF exists; `queued`: mail queued now. */
export type SendStatus = 'waiting_for_document' | 'queued';

export interface SendRecipient {
    readonly email: string;
    readonly name: string | null;
}

/** 202 answer of `POST /submissions/{uuid}/send`. */
export interface DocumentSend {
    readonly id: number;
    readonly status: SendStatus;
    readonly recipient: SendRecipient;
}

export const EDITABLE_STATUSES: readonly SubmissionStatus[] = ['draft', 'changes_requested'];

export interface PersonRef {
    readonly id: number;
    readonly name: string;
}

export type ReviewOutcome = 'approved' | 'rejected';

/** `submission.review`: the latest decision of a reviewer. */
export interface ReviewDecision {
    readonly outcome: ReviewOutcome;
    readonly comment: string | null;
    readonly by: PersonRef | null;
    readonly at: string | null;
}

export type TaskStatus = 'open' | 'completed';

export interface TaskAssignee extends PersonRef {
    readonly type: 'user' | 'group';
}

export interface TaskSubmission {
    readonly uuid: string;
    readonly number: string | null;
    readonly status: SubmissionStatus;
    readonly form: PersonRef;
    readonly submitted_by: PersonRef | null;
    readonly submitted_at: string | null;
}

/** Review task (`GET /tasks`, M4). */
export interface Task {
    readonly id: number;
    readonly type: 'review';
    readonly status: TaskStatus;
    readonly outcome: ReviewOutcome | null;
    readonly comment: string | null;
    readonly due_at: string | null;
    readonly overdue: boolean;
    readonly created_at: string;
    readonly assignee: TaskAssignee;
    readonly submission: TaskSubmission;
}

/** Body value of `POST /tasks/{id}/complete`. */
export type TaskDecision = 'approve' | 'reject';

export interface TaskCompletion {
    readonly task: Task;
    readonly submission: Submission;
}

/** In-app notification (`GET /notifications`); title/body are in the user's language. */
export interface AppNotification {
    readonly id: string;
    readonly event: string;
    readonly title: string;
    readonly body: string | null;
    /** Field App path (`/app/...`) or an admin URL; null: nothing to open. */
    readonly url: string | null;
    readonly read_at: string | null;
    readonly created_at: string;
}

export interface NotificationPage extends Page<AppNotification> {
    readonly unread: number;
}

export type NotificationChannel = 'database' | 'mail';

export const NOTIFICATION_CHANNELS: readonly NotificationChannel[] = ['database', 'mail'];

export interface NotificationPreference {
    readonly event: string;
    readonly channels: Readonly<Record<NotificationChannel, boolean>>;
}
