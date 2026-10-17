/** Submission detail "Send to customer": dialog, prefill from roles, validation, results, keys. */
import type { FormDefinition } from '@form-schema-app/form-schema-js';
import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { setupSyncScope } from '../utils/a-store.ts';
import { createBackend, fail, ok, type BackendState } from '../utils/backend.ts';
import { DEFINITION, makeDocument, makeSubmission, makeVersion } from '../utils/fixtures.ts';
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

const UUID = '0192f1c4-0000-7000-8000-0000000000e1';

const ROLE_DEFINITION: FormDefinition = {
    ...DEFINITION,
    pages: [
        {
            key: 'main',
            title: 'Main',
            elements: [
                {
                    id: '00000000-0000-4000-8000-000000000021',
                    type: 'text',
                    key: 'customer_name',
                    label: 'Customer',
                    role: 'customer.name',
                },
                {
                    id: '00000000-0000-4000-8000-000000000022',
                    type: 'email',
                    key: 'customer_email',
                    label: 'Email',
                    role: 'customer.email',
                },
            ],
        },
    ],
};

beforeEach(() => {
    setOrganizationId(1);
});

function setup(canSend = true, withRoles = true) {
    const backend = createBackend();
    backend.state.submissions.set(
        UUID,
        makeSubmission({
            uuid: UUID,
            status: 'submitted',
            number: 'SR-2026-00012',
            can_edit: false,
            can_send: canSend,
            data: { customer_name: 'Erika Kundin', customer_email: 'erika@example.test' },
        }),
    );
    server.use(...backend.handlers);
    if (withRoles) {
        server.use(
            http.get('*/api/v1/forms/:form/versions/:version', () =>
                ok({ ...makeVersion(), definition: ROLE_DEFINITION }),
            ),
        );
    }
    return backend;
}

async function openDialog(user: ReturnType<typeof renderApp>['user']) {
    // Wait for the pinned version: the prefill comes from its roles.
    await screen.findByRole('heading', { name: 'Service report', level: 1 });
    await user.click(screen.getByRole('button', { name: 'Send to customer' }));
    return screen.getByRole('dialog', { name: 'Send to customer' });
}

const keys = (state: BackendState) => state.sends.map((send) => send.key);

describe('send to customer', () => {
    it('is hidden when the submission cannot be sent', async () => {
        setup(false);
        renderApp(`/submissions/${UUID}`);
        expect(await screen.findByRole('heading', { name: 'Document (PDF)' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Send to customer' })).not.toBeInTheDocument();
    });

    it('opens an accessible dialog prefilled from the customer roles', async () => {
        setup();
        const { user, container } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAccessibleDescription('The PDF of this submission is sent by email.');
        const email = within(dialog).getByLabelText('Email address');
        expect(email).toHaveFocus();
        expect(email).toHaveValue('erika@example.test');
        expect(email).toHaveAttribute('type', 'email');
        expect(within(dialog).getByLabelText('Name (optional)')).toHaveValue('Erika Kundin');
        expect(container).toHaveAttribute('inert');
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(container).not.toHaveAttribute('inert');
        expect(screen.getByRole('button', { name: 'Send to customer' })).toHaveFocus();
    });

    it('traps focus inside the dialog and returns it on cancel', async () => {
        setup();
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        const email = within(dialog).getByLabelText('Email address');
        const send = within(dialog).getByRole('button', { name: 'Send' });
        await user.tab({ shift: true });
        expect(send).toHaveFocus();
        await user.tab();
        expect(email).toHaveFocus();
        await user.tab();
        await user.tab();
        await user.tab();
        expect(send).toHaveFocus();
        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Send to customer' })).toHaveFocus();
    });

    it('starts empty without customer roles and validates the email on the client', async () => {
        const backend = setup(true, false);
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        const email = within(dialog).getByLabelText('Email address');
        expect(email).toHaveValue('');
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        expect(email).toHaveAccessibleDescription('Enter the email address.');
        expect(email).toHaveAttribute('aria-invalid', 'true');
        expect(email).toHaveFocus();
        await user.type(email, 'kunde@example');
        expect(email).not.toHaveAccessibleDescription();
        expect(email).toHaveAttribute('aria-invalid', 'false');
        await user.keyboard('{Enter}');
        expect(email).toHaveAccessibleDescription('Enter a valid email address.');
        expect(backend.state.sends).toHaveLength(0);
    });

    it('reports "waiting for the PDF", closes the dialog and focuses the outcome', async () => {
        const backend = setup();
        const { user, container } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        const outcome = await screen.findByText(
            'Will be sent to erika@example.test as soon as the PDF is ready.',
        );
        expect(outcome.parentElement).toHaveAttribute('role', 'status');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        // The page was inert while the dialog was open: focus carries the outcome to AT.
        expect(container).not.toHaveAttribute('inert');
        expect(outcome).toHaveAttribute('tabindex', '-1');
        expect(outcome).toHaveFocus();
        expect(backend.state.sends).toEqual([
            {
                uuid: UUID,
                key: expect.stringMatching(/^[0-9a-f-]{36}$/) as unknown,
                body: { email: 'erika@example.test', name: 'Erika Kundin' },
            },
        ]);
    });

    it('reports "sent" when the PDF exists and uses a new key for the next send', async () => {
        const backend = setup();
        backend.state.documents.set(UUID, makeDocument());
        const { user } = renderApp(`/submissions/${UUID}`);
        let dialog = await openDialog(user);
        const email = within(dialog).getByLabelText('Email address');
        await user.clear(email);
        await user.type(email, ' office@kunde.test ');
        await user.clear(within(dialog).getByLabelText('Name (optional)'));
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        expect(await screen.findByText('Sending to office@kunde.test is scheduled.')).toHaveFocus();
        // A cleared name means "no name" (no fallback to the customer.name role on the server).
        expect(backend.state.sends[0]?.body).toEqual({ email: 'office@kunde.test', name: '' });
        await user.click(screen.getByRole('button', { name: 'Send to customer' }));
        dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        await screen.findByText('Sending to erika@example.test is scheduled.');
        expect(backend.state.sends[1]?.body).toEqual({
            email: 'erika@example.test',
            name: 'Erika Kundin',
        });
        expect(new Set(keys(backend.state)).size).toBe(2);
    });

    it.each([
        ['send_not_allowed', 'Sending is not allowed for this submission.'],
        ['not_submitted', 'Submit the form before sending it.'],
    ])('maps 409 %s to a localized message and keeps the key', async (code, message) => {
        setup();
        const sent: (string | null)[] = [];
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', ({ request }) => {
                sent.push(request.headers.get('Idempotency-Key'));
                return fail(409, code);
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(message);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        await vi.waitFor(() => {
            expect(sent).toHaveLength(2);
        });
        expect(sent[1]).toBe(sent[0]);
    });

    it('explains the send limit on 429 too_many_requests', async () => {
        setup();
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', () => fail(429, 'too_many_requests')),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'This document was sent too often. Please try again later.',
        );
    });

    it('shows server validation errors on the fields', async () => {
        setup();
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', () =>
                fail(422, 'validation_failed', [
                    { pointer: '/email', code: 'email' },
                    { pointer: '/name', code: 'max' },
                    { pointer: '/other', code: 'x' },
                ]),
            ),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'Please check the marked fields.',
        );
        const email = within(dialog).getByLabelText('Email address');
        expect(email).toHaveAttribute('aria-invalid', 'true');
        expect(email).toHaveAccessibleDescription('Enter a valid email address.');
        // The focus moves in an effect after the errors render (the answer now comes via the outbox).
        await vi.waitFor(() => {
            expect(email).toHaveFocus();
        });
        expect(within(dialog).getByLabelText('Name (optional)')).toHaveAccessibleDescription(
            'Check the name.',
        );
    });

    it('queues the send after a network error and sends it with the same key once online', async () => {
        const backend = setup();
        let calls = 0;
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', ({ request }) => {
                calls++;
                if (calls > 1) {
                    return undefined;
                }
                backend.state.sends.push({
                    uuid: UUID,
                    key: request.headers.get('Idempotency-Key'),
                    body: null,
                });
                return HttpResponse.error();
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        // Queued in the outbox (persisted key and body): the UI says it goes out after sync.
        await vi.waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
        const queued = screen.getByText(/Sending to erika@example.test is queued/);
        expect(queued.closest('[role="status"]')).not.toBeNull();
        expect(queued).toHaveFocus();
        window.dispatchEvent(new Event('online'));
        await screen.findByText(/Will be sent to erika@example.test/);
        expect(screen.queryByText(/is queued/)).not.toBeInTheDocument();
        const [first, retry] = keys(backend.state);
        expect(retry).toBe(first);
        await user.click(screen.getByRole('button', { name: 'Send to customer' }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Send' }));
        await vi.waitFor(() => {
            expect(keys(backend.state)).toHaveLength(3);
        });
        expect(keys(backend.state)[2]).not.toBe(first);
    });

    it('sends again at once with a new key after idempotency_key_reused', async () => {
        const backend = setup();
        let calls = 0;
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', ({ request }) => {
                calls++;
                if (calls > 1) {
                    return undefined;
                }
                backend.state.sends.push({
                    uuid: UUID,
                    key: request.headers.get('Idempotency-Key'),
                    body: null,
                });
                return fail(409, 'idempotency_key_reused');
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        await screen.findByText(/Will be sent to/);
        const [first, retry] = keys(backend.state);
        expect(retry).not.toBe(first);
    });

    it('shows a failure that arrives after the dialog was closed', async () => {
        setup();
        let release: () => void = () => undefined;
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        let posts = 0;
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', async () => {
                posts++;
                await gate;
                return fail(404, 'not_found');
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        expect(within(dialog).getByRole('button', { name: 'Sending…' })).toHaveAttribute(
            'aria-disabled',
            'true',
        );
        await user.keyboard('{Enter}');
        await user.keyboard('{Escape}');
        release();
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This submission is no longer available.',
        );
        expect(posts).toBe(1);
    });

    it('announces a success that arrives after the dialog was closed, without moving focus', async () => {
        setup();
        let release: () => void = () => undefined;
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', async () => {
                await gate;
                return undefined;
            }),
        );
        const { user, container } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        await user.keyboard('{Escape}');
        expect(container).not.toHaveAttribute('inert');
        const opener = screen.getByRole('button', { name: 'Send to customer' });
        expect(opener).toHaveFocus();
        release();
        const outcome = await screen.findByText(/Will be sent to erika@example.test/);
        expect(outcome.parentElement).toHaveAttribute('role', 'status');
        expect(opener).toHaveFocus();
    });

    it('does not close a dialog that was reopened while an earlier send was running', async () => {
        const backend = setup();
        let release: () => void = () => undefined;
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        server.use(
            http.post('*/api/v1/submissions/:uuid/send', async () => {
                await gate;
                return undefined;
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        await user.keyboard('{Escape}');
        await user.click(screen.getByRole('button', { name: 'Send to customer' }));
        release();
        await screen.findByText(/Will be sent to erika@example.test/);
        expect(screen.getByRole('dialog', { name: 'Send to customer' })).toBeInTheDocument();
        expect(backend.state.sends).toHaveLength(1);
    });

    it('maps unexpected errors to a generic message', async () => {
        setup();
        server.use(http.post('*/api/v1/submissions/:uuid/send', () => fail(500, 'server_error')));
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openDialog(user);
        await user.click(within(dialog).getByRole('button', { name: 'Send' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'Sending failed. Please try again.',
        );
    });
});
