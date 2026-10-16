/** Submission detail: approve / request changes on an open review task (idempotent, errors, focus). */
import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { createBackend, fail } from '../utils/backend.ts';
import { makeSubmission, makeTask } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();

const UUID = '0192f1c4-0000-7000-8000-0000000000a1';
const TASK_ID = 41;
const TASK_CLOSED =
    'This review has already been completed or is no longer needed. The current state is shown.';

beforeEach(() => {
    setOrganizationId(1);
});

function setup(openTask: number | null = TASK_ID) {
    const backend = createBackend({ tasks: [makeTask({ id: TASK_ID })] });
    backend.state.submissions.set(
        UUID,
        makeSubmission({
            uuid: UUID,
            status: 'in_review',
            number: 'SR-2026-00012',
            can_edit: false,
            open_task_id: openTask,
        }),
    );
    server.use(...backend.handlers);
    return backend;
}

type User = ReturnType<typeof renderApp>['user'];

async function panel() {
    return screen.findByRole('region', { name: 'Your review' });
}

async function openRequestChanges(user: User) {
    await user.click(within(await panel()).getByRole('button', { name: 'Request changes' }));
    return screen.getByRole('dialog', { name: 'Request changes?' });
}

async function openApprove(user: User) {
    await user.click(within(await panel()).getByRole('button', { name: 'Approve' }));
    return screen.getByRole('dialog', { name: 'Approve submission?' });
}

describe('review decision', () => {
    it('shows no review panel without an open task for the user', async () => {
        setup(null);
        renderApp(`/submissions/${UUID}`);
        await screen.findByRole('heading', { name: 'Document (PDF)' });
        expect(screen.queryByRole('region', { name: 'Your review' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    });

    it('approves after confirmation, refreshes and announces the outcome', async () => {
        const backend = setup();
        const { user, container } = renderApp(`/submissions/${UUID}`);
        expect(await panel()).toHaveTextContent('This submission is waiting for your decision.');
        const dialog = await openApprove(user);
        expect(dialog).toHaveAccessibleDescription('The workflow continues with the next step.');
        expect(within(dialog).getByRole('button', { name: 'Cancel' })).toHaveFocus();
        expect(container).toHaveAttribute('inert');
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        const outcome = await screen.findByText('You approved this submission.');
        expect(outcome.parentElement).toHaveAttribute('role', 'status');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(outcome).toHaveFocus();
        expect(backend.state.completions).toEqual([
            {
                id: TASK_ID,
                key: expect.stringMatching(/^[0-9a-f-]{36}$/) as unknown,
                body: { outcome: 'approve' },
            },
        ]);
        expect(await screen.findByText('Approved')).toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Your review' })).not.toBeInTheDocument();
    });

    it('cancels the confirmation and returns focus to the button', async () => {
        const backend = setup();
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(within(await panel()).getByRole('button', { name: 'Approve' })).toHaveFocus();
        expect(backend.state.completions).toHaveLength(0);
    });

    it('requires a comment to request changes, then requests them and shows the comment', async () => {
        const backend = setup();
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openRequestChanges(user);
        expect(dialog).toHaveAccessibleDescription(
            'The submitter is notified and can edit and resubmit the form.',
        );
        const comment = within(dialog).getByLabelText('What needs to be changed?');
        expect(comment).toHaveFocus();
        expect(comment).toHaveAttribute('maxlength', '2000');
        await user.type(comment, '   ');
        await user.click(within(dialog).getByRole('button', { name: 'Request changes' }));
        expect(comment).toHaveAttribute('aria-invalid', 'true');
        expect(comment).toHaveAccessibleDescription('Please describe what needs to be changed.');
        expect(comment).toHaveFocus();
        expect(backend.state.completions).toHaveLength(0);
        await user.type(comment, 'Bitte Seriennummer ergänzen ');
        expect(comment).not.toHaveAccessibleDescription();
        await user.click(within(dialog).getByRole('button', { name: 'Request changes' }));
        const outcome = await screen.findByText(
            'You requested changes. The submitter has been notified.',
        );
        expect(outcome).toHaveFocus();
        expect(backend.state.completions[0]?.body).toEqual({
            outcome: 'reject',
            comment: 'Bitte Seriennummer ergänzen',
        });
        const note = await screen.findByRole('region', { name: 'Changes requested' });
        expect(note).toHaveTextContent('Bitte Seriennummer ergänzen');
        expect(note).toHaveTextContent(/Rita Reviewer on Oct 16, 2026/);
        // The reviewer cannot edit: no resubmit action.
        expect(within(note).queryByRole('link')).not.toBeInTheDocument();
    });

    it('closes the request-changes dialog with Escape and returns focus', async () => {
        setup();
        const { user } = renderApp(`/submissions/${UUID}`);
        await openRequestChanges(user);
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            within(await panel()).getByRole('button', { name: 'Request changes' }),
        ).toHaveFocus();
    });

    it('says so when someone else already decided (409 task_closed) and refreshes', async () => {
        const backend = setup();
        backend.state.tasks = [makeTask({ id: TASK_ID, status: 'completed' })];
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        backend.state.submissions.set(UUID, {
            ...backend.state.submissions.get(UUID)!,
            status: 'approved',
            open_task_id: null,
        });
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        const alert = await screen.findByText(TASK_CLOSED);
        expect(alert).toHaveAttribute('role', 'alert');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(alert).toHaveFocus();
        expect(await screen.findByText('Approved')).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.queryByRole('region', { name: 'Your review' })).not.toBeInTheDocument();
        });
    });

    it('treats a cancelled task (409 task_closed) as no longer needed and refreshes', async () => {
        const backend = setup();
        backend.state.tasks = [makeTask({ id: TASK_ID, status: 'cancelled' })];
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openRequestChanges(user);
        await user.type(within(dialog).getByLabelText('What needs to be changed?'), 'Too late');
        // Meanwhile the office voided the submission; the task was cancelled.
        backend.state.submissions.set(UUID, {
            ...backend.state.submissions.get(UUID)!,
            status: 'void',
            open_task_id: null,
        });
        await user.click(within(dialog).getByRole('button', { name: 'Request changes' }));
        expect(await screen.findByText(TASK_CLOSED)).toHaveFocus();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(await screen.findByText('Void')).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.queryByRole('region', { name: 'Your review' })).not.toBeInTheDocument();
        });
        expect(sessionStorage.getItem(`fsa.idem.task.${String(TASK_ID)}`)).toBeNull();
    });

    it('explains 409 review_blocked and keeps the task open for a later retry', async () => {
        setup();
        server.use(http.post('*/api/v1/tasks/:id/complete', () => fail(409, 'review_blocked')));
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        expect(
            await within(dialog).findByText(
                'The workflow of this submission is paused because of an error. The office has been notified; please try again later.',
            ),
        ).toHaveAttribute('role', 'alert');
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('explains 409 invalid_transition once, closes the dialog and refreshes (no retry loop)', async () => {
        const backend = setup();
        server.use(
            http.post('*/api/v1/tasks/:id/complete', () => {
                backend.state.completions.push({ id: TASK_ID, key: null, body: null });
                return fail(409, 'invalid_transition');
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        backend.state.submissions.set(UUID, {
            ...backend.state.submissions.get(UUID)!,
            status: 'completed',
            open_task_id: null,
        });
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        const alert = await screen.findByText(
            'The status of this submission has changed, so this review is no longer possible. The current state is shown.',
        );
        expect(alert).toHaveAttribute('role', 'alert');
        expect(alert).toHaveFocus();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(await screen.findByText('Completed')).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.queryByRole('region', { name: 'Your review' })).not.toBeInTheDocument();
        });
        expect(backend.state.completions).toHaveLength(1);
        expect(sessionStorage.getItem(`fsa.idem.task.${String(TASK_ID)}`)).toBeNull();
    });

    it('explains 404 for a task the user can no longer act on', async () => {
        const backend = setup();
        backend.state.tasks = [];
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        expect(await screen.findByText('You can no longer complete this review.')).toHaveFocus();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('shows server validation errors next to the comment or as a summary', async () => {
        setup();
        server.use(
            http.post(
                '*/api/v1/tasks/:id/complete',
                () => fail(422, 'validation_failed', [{ pointer: '/comment', code: 'max' }]),
                { once: true },
            ),
            http.post('*/api/v1/tasks/:id/complete', () =>
                fail(422, 'validation_failed', [{ pointer: '/outcome', code: 'in' }]),
            ),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openRequestChanges(user);
        const comment = within(dialog).getByLabelText('What needs to be changed?');
        await user.type(comment, 'Zu lang');
        await user.click(within(dialog).getByRole('button', { name: 'Request changes' }));
        await waitFor(() => {
            expect(comment).toHaveAccessibleDescription(
                'Please check the comment (at most 2000 characters).',
            );
        });
        expect(comment).toHaveAttribute('aria-invalid', 'true');
        expect(comment).toHaveFocus();
        expect(within(dialog).getByRole('alert')).toHaveTextContent('Please check your input.');
        await user.click(within(dialog).getByRole('button', { name: 'Request changes' }));
        await waitFor(() => {
            expect(comment).not.toHaveAccessibleDescription();
        });
        expect(within(dialog).getByRole('alert')).toHaveTextContent('Please check your input.');
    });

    it('reuses the Idempotency-Key after a network error until it succeeds', async () => {
        const backend = setup();
        const sent: (string | null)[] = [];
        server.use(
            http.post(
                '*/api/v1/tasks/:id/complete',
                ({ request }) => {
                    sent.push(request.headers.get('Idempotency-Key'));
                    return HttpResponse.error();
                },
                { once: true },
            ),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'The connection was interrupted. Trying again is safe – the decision is recorded only once.',
        );
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        expect(await screen.findByText('You approved this submission.')).toBeInTheDocument();
        expect(backend.state.completions[0]?.key).toBe(sent[0]);
        expect(sessionStorage.getItem(`fsa.idem.task.${String(TASK_ID)}`)).toBeNull();
    });

    it('uses a fresh key after idempotency_key_reused', async () => {
        const backend = setup();
        const sent: (string | null)[] = [];
        server.use(
            http.post(
                '*/api/v1/tasks/:id/complete',
                ({ request }) => {
                    sent.push(request.headers.get('Idempotency-Key'));
                    return fail(409, 'idempotency_key_reused');
                },
                { once: true },
            ),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'Your decision changed while saving. Please confirm it again.',
        );
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        await screen.findByText('You approved this submission.');
        expect(backend.state.completions[0]?.key).not.toBe(sent[0]);
    });

    it('maps unexpected errors to a generic message', async () => {
        setup();
        server.use(http.post('*/api/v1/tasks/:id/complete', () => fail(500, 'server_error')));
        const { user } = renderApp(`/submissions/${UUID}`);
        const dialog = await openApprove(user);
        await user.click(within(dialog).getByRole('button', { name: 'Approve' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'The decision could not be saved. Please try again.',
        );
    });

    it('speaks German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        setup();
        const { user } = renderApp(`/submissions/${UUID}`);
        const region = await screen.findByRole('region', { name: 'Ihre Prüfung' });
        expect(within(region).queryByRole('button', { name: 'Ablehnen' })).not.toBeInTheDocument();
        await user.click(within(region).getByRole('button', { name: 'Änderungen anfordern' }));
        const dialog = screen.getByRole('dialog', { name: 'Änderungen anfordern?' });
        expect(
            within(dialog).getByRole('button', { name: 'Änderungen anfordern' }),
        ).toBeInTheDocument();
        expect(within(dialog).getByLabelText('Was soll geändert werden?')).toHaveFocus();
        await user.click(within(dialog).getByRole('button', { name: 'Abbrechen' }));
        await user.click(within(region).getByRole('button', { name: 'Freigeben' }));
        expect(
            screen.getByRole('dialog', { name: 'Einreichung freigeben?' }),
        ).toHaveAccessibleDescription('Der Workflow wird mit dem nächsten Schritt fortgesetzt.');
    });
});
