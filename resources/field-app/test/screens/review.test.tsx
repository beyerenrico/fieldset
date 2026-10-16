import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createBackend, fail } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();

const UUID = '0192f1c4-0000-7000-8000-0000000000bb';

beforeEach(() => {
    setOrganizationId(1);
});

function setup(data: Record<string, unknown> = { customer: 'ACME' }) {
    const backend = createBackend();
    backend.state.submissions.set(
        UUID,
        makeSubmission({ uuid: UUID, revision: 3, data: data as never }),
    );
    server.use(...backend.handlers);
    return backend;
}

describe('review and submit', () => {
    it('goes from fill to review and submits with an Idempotency-Key', async () => {
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
            await screen.findByText('Number SR-2026-00012', { selector: 'p' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Submitted', { selector: 'p' })).toBeInTheDocument();
        expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        expect(backend.state.submits).toHaveLength(1);
        expect(backend.state.submits[0]?.key).toMatch(/^[0-9a-f-]{36}$/);
        expect(backend.state.submits[0]?.body).toEqual({ revision: 3 });
        expect(sessionStorage.length).toBe(0);
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
        expect(backend.state.submits).toHaveLength(0);
    });

    it('shows server pointer errors and reuses the key after a network failure', async () => {
        setup();
        const keys: (string | null)[] = [];
        let attempt = 0;
        server.use(
            http.post('*/api/v1/submissions/:uuid/submit', ({ request }) => {
                keys.push(request.headers.get('Idempotency-Key'));
                attempt++;
                if (attempt === 1) {
                    return HttpResponse.error();
                }
                return fail(422, 'validation_failed', [
                    { pointer: '/items/0/name', code: 'required' },
                ]);
            }),
        );
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(await screen.findByText(/You are offline/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(
            await screen.findByText('The server found problems in the marked fields.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('list', { name: 'renderer errors' })).toHaveTextContent(
            '/items/0/name:required',
        );
        expect(keys[0]).not.toBeNull();
        expect(keys[1]).toBe(keys[0]);
    });

    it.each([
        ['version_retired', 409, 'This form version was retired. Start a new submission.'],
        ['form_access_revoked', 403, 'You no longer have access to this form.'],
        ['attachments_missing', 422, 'Some attachments are missing. Add them again and retry.'],
        ['http_500', 500, 'Submitting failed. Please try again.'],
    ])('explains %s', async (code, status, text) => {
        setup();
        server.use(http.post('*/api/v1/submissions/:uuid/submit', () => fail(status, code)));
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(await screen.findByText(text)).toBeInTheDocument();
    });

    it('generates a fresh key after idempotency_key_reused', async () => {
        setup();
        const keys: (string | null)[] = [];
        server.use(
            http.post('*/api/v1/submissions/:uuid/submit', ({ request }) => {
                keys.push(request.headers.get('Idempotency-Key'));
                return fail(409, 'idempotency_key_reused');
            }),
        );
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        await screen.findByText(/changed while submitting/);
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        await waitFor(() => {
            expect(keys).toHaveLength(2);
        });
        expect(keys[1]).not.toBe(keys[0]);
    });

    it('adopts an identical server copy on a submit conflict and submits again', async () => {
        const backend = setup();
        const same = makeSubmission({ uuid: UUID, revision: 4, data: { customer: 'ACME' } });
        let attempt = 0;
        server.use(
            http.post('*/api/v1/submissions/:uuid/submit', async ({ request }) => {
                backend.state.submits.push({
                    uuid: UUID,
                    key: request.headers.get('Idempotency-Key'),
                    body: await request.json(),
                });
                attempt++;
                return attempt === 1
                    ? fail(409, 'revision_conflict', { submission: same })
                    : HttpResponse.json({
                          success: true,
                          data: { submission: { ...same, status: 'submitted', number: 'N-1' } },
                          error: null,
                          meta: null,
                      });
            }),
        );
        const { user, history } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        await waitFor(() => {
            expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        });
        expect(backend.state.submits.map((submit) => submit.body)).toEqual([
            { revision: 3 },
            { revision: 4 },
        ]);
        expect(backend.state.submits[1]?.key).not.toBe(backend.state.submits[0]?.key);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('opens the conflict panel on a submit revision_conflict', async () => {
        setup();
        const server_copy = makeSubmission({
            uuid: UUID,
            revision: 9,
            data: { customer: 'Other' },
        });
        server.use(
            http.post('*/api/v1/submissions/:uuid/submit', () =>
                fail(409, 'revision_conflict', { submission: server_copy }),
            ),
        );
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(await screen.findByRole('alertdialog')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
    });
});
