import type { FormDefinition } from '@fieldset/fieldset-js';
import type {
    AppNotification,
    FormSummary,
    FormVersion,
    Me,
    NotificationPreference,
    Organization,
    Submission,
    SubmissionDocument,
    Task,
} from '../../src/lib/api/types.ts';

export const ORG_A: Organization = {
    id: 1,
    name: 'Demo GmbH',
    slug: 'demo',
    locale: 'de-DE',
    timezone: 'Europe/Berlin',
    branding: null,
    roles: ['worker'],
    permissions: [],
    groups: [{ id: 5, name: 'Field' }],
};

export const ORG_B: Organization = { ...ORG_A, id: 2, name: 'Second AG', slug: 'second' };

export function makeMe(organizations: readonly Organization[] = [ORG_A]): Me {
    return {
        user: {
            id: 7,
            name: 'Willi Worker',
            email: 'worker@demo.test',
            locale: 'en',
            timezone: 'Europe/Berlin',
            email_verified: true,
            mfa_enabled: false,
        },
        organizations,
    };
}

export const DEFINITION: FormDefinition = {
    specVersion: '1.0.0',
    key: 'service_report',
    title: 'Service report',
    locale: 'en-US',
    pages: [
        {
            key: 'main',
            title: 'Main',
            elements: [
                {
                    id: '00000000-0000-4000-8000-000000000001',
                    type: 'text',
                    key: 'customer',
                    label: 'Customer',
                    required: true,
                },
                {
                    id: '00000000-0000-4000-8000-000000000002',
                    type: 'repeater',
                    key: 'items',
                    label: 'Items',
                    elements: [
                        {
                            id: '00000000-0000-4000-8000-000000000003',
                            type: 'text',
                            key: 'name',
                            label: 'Name',
                        },
                    ],
                },
                {
                    id: '00000000-0000-4000-8000-000000000004',
                    type: 'calculated',
                    key: 'count',
                    label: 'Count',
                    expression: { count: [{ var: 'items' }] },
                },
            ],
        },
    ],
};

export const FORMS: readonly FormSummary[] = [
    {
        id: 3,
        key: 'service_report',
        name: 'Service report',
        category: 'Service',
        version: { id: 7, version: 2, checksum: 'abc' },
    },
    {
        id: 4,
        key: 'inspection',
        name: 'Inspection',
        category: 'Safety',
        version: { id: 8, version: 1, checksum: 'def' },
    },
    {
        id: 5,
        key: 'timesheet',
        name: 'Timesheet',
        category: null,
        version: { id: 9, version: 1, checksum: 'ghi' },
    },
];

export function makeVersion(id = 7, formId = 3): FormVersion {
    return {
        id,
        form_id: formId,
        version: 2,
        checksum: 'abc',
        definition: DEFINITION,
        design: null,
        published_at: '2026-10-01T10:00:00+02:00',
        retired: false,
    };
}

export function makeSubmission(overrides: Partial<Submission> = {}): Submission {
    return {
        uuid: '0192f1c4-0000-7000-8000-000000000001',
        form_id: 3,
        form_version_id: 7,
        version: 2,
        status: 'draft',
        revision: 1,
        number: null,
        data: { customer: 'ACME' },
        attachments: [],
        submitted_at: null,
        client_submitted_at: null,
        created_at: '2026-10-16T08:00:00+02:00',
        updated_at: '2026-10-16T09:00:00+02:00',
        can_edit: true,
        can_send: false,
        ...overrides,
    };
}

export function makeDocument(overrides: Partial<SubmissionDocument> = {}): SubmissionDocument {
    return {
        url: 'http://localhost:3000/api/v1/documents/r1?user=7&expires=1&signature=abc',
        expires_at: new Date(Date.now() + 5 * 60_000).toISOString(),
        rendition: {
            uuid: 'r1',
            form_version_id: 7,
            design_version_id: 1,
            renderer_version: 'gotenberg-8',
            page_count: 2,
            size: 48_213,
            file_sha256: 'f'.repeat(64),
            input_hash: 'e'.repeat(64),
            created_at: '2026-10-16T09:42:00+02:00',
        },
        ...overrides,
    };
}

export function makeTask(overrides: Partial<Task> = {}): Task {
    return {
        id: 41,
        type: 'review',
        status: 'open',
        outcome: null,
        comment: null,
        due_at: '2026-10-20T12:00:00+02:00',
        overdue: false,
        created_at: '2026-10-16T10:00:00+02:00',
        assignee: { type: 'group', id: 9, name: 'Reviewers' },
        submission: {
            uuid: '0192f1c4-0000-7000-8000-0000000000a1',
            number: 'SR-2026-00012',
            status: 'in_review',
            form: { id: 3, name: 'Service report' },
            submitted_by: { id: 8, name: 'Susi Submitter' },
            submitted_at: '2026-10-16T09:41:00+02:00',
        },
        ...overrides,
    };
}

export function makeNotification(overrides: Partial<AppNotification> = {}): AppNotification {
    return {
        id: 'n1',
        event: 'review_assigned',
        title: 'Review requested',
        body: 'Service report SR-2026-00012 is waiting for your review.',
        url: '/app/submissions/0192f1c4-0000-7000-8000-0000000000a1',
        organization_id: 1,
        read_at: null,
        created_at: '2026-10-16T10:00:00+02:00',
        ...overrides,
    };
}

export const PREFERENCES: readonly NotificationPreference[] = [
    { event: 'review_assigned', channels: { database: true, mail: true } },
    { event: 'changes_requested', channels: { database: true, mail: false } },
    { event: 'submission_approved', channels: { database: false, mail: false } },
    { event: 'form_available', channels: { database: true, mail: false } },
];
