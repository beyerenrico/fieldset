import type { FormDefinition } from '@form-schema-app/form-schema-js';
import type {
    FormSummary,
    FormVersion,
    Me,
    Organization,
    Submission,
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
        created_at: '2026-10-16T08:00:00+02:00',
        updated_at: '2026-10-16T09:00:00+02:00',
        can_edit: true,
        ...overrides,
    };
}
