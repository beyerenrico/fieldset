/**
 * Review and submit (§9.2, ADR 0035): the submit goes through the outbox with a persisted
 * Idempotency-Key; online the answer is shown at once, offline the submission is queued.
 */
import type { FormDefinition } from '@form-schema-app/form-schema-js';
import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { enqueueSubmit, opId } from '../../src/lib/outbox/outbox.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createSyncBackend, failWith, summarize } from '../utils/a-backend.ts';
import { SCOPE, draftRow, setupSyncScope } from '../utils/a-store.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { DEFINITION, makeSubmission, makeVersion } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000bb';
const anyTime: unknown = expect.stringMatching(/^\d{4}-\d\d-\d\dT/);

beforeEach(() => {
    setOrganizationId(1);
});
afterEach(() => {
    vi.restoreAllMocks();
});

function setup(data: Record<string, unknown> = { customer: 'ACME' }) {
    const reads = createBackend();
    const writes = createSyncBackend({ submissions: reads.state.submissions });
    reads.state.submissions.set(
        UUID,
        makeSubmission({ uuid: UUID, revision: 3, data: data as never }),
    );
    server.use(...writes.handlers, ...reads.handlers);
    return writes;
}

const submits = (backend: ReturnType<typeof setup>) =>
    backend.state.log.filter((entry) => entry.path.endsWith('/submit'));

describe('review and submit', () => {
    it('goes from fill to review and submits with a persisted Idempotency-Key', async () => {
        const backend = setup();
        const { user, history } = renderApp(`/fill/${UUID}`);
        await screen.findByRole('heading', { name: 'Service report' });
        await user.click(screen.getByRole('button', { name: 'Review' }));
        expect(await screen.findByRole('heading', { name: 'Review and sign' })).toBeInTheDocument();
        const renderer = screen.getByTestId('renderer');
        expect(renderer).toHaveAttribute('data-readonly', 'true');
        expect(renderer).toHaveAttribute('data-except', 'signature');
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(
            await screen.findByText('Number SR-2026-00001', { selector: 'p' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Submitted', { selector: 'p' })).toBeInTheDocument();
        expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        expect(submits(backend)).toHaveLength(1);
        expect(submits(backend)[0]?.key).toMatch(/^[0-9a-f-]{36}$/);
        expect(submits(backend)[0]?.body).toEqual({ revision: 3, client_submitted_at: anyTime });
        // The key lives with the queued request in IndexedDB, never in sessionStorage.
        expect(sessionStorage.length).toBe(0);
        expect(await db.outbox.count()).toBe(0);
    });

    it('blocks submitting when client validation fails', async () => {
        const backend = setup({});
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(
            await screen.findByText('Please fix the marked fields before submitting.'),
        ).toBeInTheDocument();
        expect(screen.getByTestId('renderer')).toHaveAttribute('data-show-errors', 'true');
        expect(submits(backend)).toHaveLength(0);
        expect(await db.outbox.count()).toBe(0);
    });

    it('queues the submit offline ("number pending") and retries it with the same key', async () => {
        const backend = setup();
        let attempt = 0;
        server.use(
            http.post('*/api/v1/submissions/:uuid/submit', ({ request }) => {
                backend.state.log.push({
                    method: 'POST',
                    path: `/api/v1/submissions/${UUID}/submit`,
                    org: request.headers.get('X-Organization-Id'),
                    key: request.headers.get('Idempotency-Key'),
                    body: null,
                });
                attempt++;
                return attempt === 1
                    ? HttpResponse.error()
                    : fail(422, 'validation_failed', [
                          { pointer: '/items/0/name', code: 'required' },
                      ]);
            }),
        );
        const { user, history } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));

        expect(await screen.findByText('Ready to send')).toBeInTheDocument();
        expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        expect(screen.getAllByText('Number pending')).toHaveLength(1);
        expect(screen.getByText('Waiting to sync')).toBeInTheDocument();

        window.dispatchEvent(new Event('online')); // back online: the queue runs again
        await waitFor(() => {
            expect(screen.queryByText('Ready to send')).not.toBeInTheDocument();
        });
        // A refusal nobody waited for does not silently turn back into a draft (F1 contract).
        expect(
            await screen.findByText(
                'Not submitted: 1 field needs fixing. Open the form, correct the marked field and submit again.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open the form' })).toHaveAttribute(
            'href',
            `/app/fill/${UUID}/review`,
        );
        const keys = backend.state.log.map((entry) => entry.key);
        expect(keys).toHaveLength(2);
        expect(keys[1]).toBe(keys[0]);

        history.push(`/app/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(screen.getByRole('list', { name: 'renderer errors' })).toHaveTextContent(
            '/items/0/name:required',
        );
    });

    it('shows server pointer errors of a refused submit', async () => {
        const backend = setup();
        backend.state.inject.set(
            'POST submit',
            failWith(422, 'validation_failed', [{ pointer: '/items/0/name', code: 'required' }]),
        );
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(
            await screen.findByText('The server found problems in the marked fields.'),
        ).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.getByRole('list', { name: 'renderer errors' })).toHaveTextContent(
                '/items/0/name:required',
            );
        });
        expect(await db.outbox.get(opId('submit', UUID))).toBeUndefined();
    });

    it.each([
        ['version_retired', 409, 'This form version was retired. Start a new submission.'],
        ['form_access_revoked', 403, 'You no longer have access to this form.'],
        ['attachments_missing', 422, 'Some attachments are missing. Add them again and retry.'],
    ])('explains %s', async (code, status, text) => {
        const backend = setup();
        backend.state.inject.set('POST submit', failWith(status, code));
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(await screen.findByText(text)).toBeInTheDocument();
    });

    it('keeps a submit that met a server error queued and says it is retried', async () => {
        const backend = setup();
        backend.state.inject.set('POST submit', failWith(500, 'server_error'));
        const { user, history } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(await screen.findByText('Ready to send')).toBeInTheDocument();
        expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        expect(
            screen.getByText(
                'The last attempt failed because of a temporary problem. It is tried again automatically.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/server_error/)).not.toBeInTheDocument();
        expect(await db.outbox.get(opId('submit', UUID))).toMatchObject({ attempts: 1 });
    });

    it('sends again with a fresh key after idempotency_key_reused', async () => {
        const backend = setup();
        backend.state.inject.set('POST submit', () => {
            backend.state.inject.delete('POST submit');
            return failWith(409, 'idempotency_key_reused')();
        });
        const { user, history } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        await waitFor(() => {
            expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        });
        const keys = submits(backend).map((entry) => entry.key);
        expect(keys).toHaveLength(2);
        expect(keys[1]).not.toBe(keys[0]);
    });

    it('adopts an identical server copy on a submit conflict and submits again', async () => {
        const backend = setup();
        const same = makeSubmission({ uuid: UUID, revision: 4, data: { customer: 'ACME' } });
        backend.state.inject.set('POST submit', () => {
            backend.state.inject.delete('POST submit');
            backend.state.submissions.set(UUID, same);
            return failWith(409, 'revision_conflict', { submission: same })();
        });
        const { user, history } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        await waitFor(() => {
            expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        });
        expect(submits(backend).map((submit) => submit.body)).toEqual([
            { revision: 3, client_submitted_at: anyTime },
            { revision: 4, client_submitted_at: anyTime },
        ]);
        expect(submits(backend)[1]?.key).not.toBe(submits(backend)[0]?.key);
        expect(
            screen.queryByRole('region', { name: 'Someone else changed this draft' }),
        ).not.toBeInTheDocument();
    });

    it('opens the conflict panel on a submit revision_conflict; the submit waits', async () => {
        const backend = setup();
        const serverCopy = makeSubmission({ uuid: UUID, revision: 9, data: { customer: 'Other' } });
        backend.state.inject.set(
            'POST submit',
            failWith(409, 'revision_conflict', { submission: serverCopy }),
        );
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        const panel = await screen.findByRole('region', {
            name: 'Someone else changed this draft',
        });
        // The panel follows the user's own action (Submit): focus moves to its heading.
        await waitFor(() => {
            expect(within(panel).getByRole('heading', { level: 2 })).toHaveFocus();
        });
        expect(
            await screen.findByText('The draft was changed elsewhere. Choose a version first.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
        // The submit waits behind the decision (F1 contract: a paused submission's ops are blocked).
        expect(await db.outbox.get(opId('submit', UUID))).toMatchObject({
            kind: 'submit',
            state: 'blocked',
        });
    });
});

/** A form that may be sent to the customer, with the customer roles for the prefill. */
const SEND_DEFINITION: FormDefinition = {
    ...DEFINITION,
    settings: { sendToCustomer: true },
    pages: [
        {
            key: 'main',
            title: 'Main',
            elements: [
                {
                    id: '00000000-0000-4000-8000-000000000031',
                    type: 'text',
                    key: 'customer_name',
                    label: 'Customer',
                    role: 'customer.name',
                },
                {
                    id: '00000000-0000-4000-8000-000000000032',
                    type: 'email',
                    key: 'customer_email',
                    label: 'Email',
                    role: 'customer.email',
                },
            ],
        },
    ],
};

describe('send behind a queued submission (offline, §9.2)', () => {
    it('queues a send behind a queued submit; after sync it goes out after the submit', async () => {
        const reads = createBackend();
        const writes = createSyncBackend({ submissions: reads.state.submissions, offline: true });
        server.use(...writes.handlers, ...reads.handlers);
        server.use(
            http.get('*/api/v1/forms/:form/versions/:version', () =>
                ok({
                    ...makeVersion(),
                    definition: SEND_DEFINITION,
                }),
            ),
            http.get(`*/api/v1/submissions/${UUID}`, () =>
                writes.state.offline ? HttpResponse.error() : undefined,
            ),
        );
        await db.drafts.put(
            draftRow({
                uuid: UUID,
                data: { customer_name: 'Erika Kundin', customer_email: 'erika@example.test' },
            }),
        );
        await enqueueSubmit(SCOPE, UUID, '2026-10-17T09:00:00.000Z');
        const { user } = renderApp(`/submissions/${UUID}`);
        expect(await screen.findByText('Ready to send')).toBeInTheDocument();
        await screen.findByRole('heading', { name: 'Service report', level: 1 });
        await user.click(screen.getByRole('button', { name: 'Send to customer' }));
        const dialog = screen.getByRole('dialog', { name: 'Send to customer' });
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        await vi.waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
        expect(screen.getByText(/Sending to erika@example.test is queued/)).toBeInTheDocument();

        writes.state.offline = false;
        window.dispatchEvent(new Event('online'));
        await vi.waitFor(() => {
            expect(summarize(writes.state.log)).toEqual(['PUT create', 'SUBMIT', 'SEND']);
        });
        expect(writes.state.log[2]?.body).toEqual({
            email: 'erika@example.test',
            name: 'Erika Kundin',
        });
        await vi.waitFor(() => {
            expect(screen.queryByText('Ready to send')).not.toBeInTheDocument();
        });
    });
});
