/** Submission detail "Document (PDF)": ready link, polling while rendered, errors. */
import { focusManager, onlineManager, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createAppQueryClient } from '../../src/App.tsx';
import { I18nProvider } from '../../src/i18n/I18nProvider.tsx';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { DocumentStatus } from '../../src/screens/detail/DocumentStatus.tsx';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { makeDocument, makeSubmission } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();

const UUID = '0192f1c4-0000-7000-8000-0000000000d1';
const NOT_READY = 'The PDF is being created…';

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    setOrganizationId(1);
});
afterEach(() => {
    vi.useRealTimers();
});

function setup(status: 'submitted' | 'draft' = 'submitted') {
    const backend = createBackend();
    backend.state.submissions.set(
        UUID,
        makeSubmission({ uuid: UUID, status, number: 'SR-2026-00012', can_edit: false }),
    );
    server.use(...backend.handlers);
    return backend;
}

describe('submission document', () => {
    it('opens the signed PDF link in a new tab when the rendition exists', async () => {
        const backend = setup();
        const document = makeDocument();
        backend.state.documents.set(UUID, document);
        renderApp(`/submissions/${UUID}`);
        expect(
            await screen.findByRole('heading', { name: 'Document (PDF)', level: 2 }),
        ).toBeInTheDocument();
        const link = await screen.findByRole('link', { name: 'Open PDF (opens in a new tab)' });
        expect(link).toHaveAttribute('href', document.url);
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(backend.state.documentCalls).toBe(1);
    });

    it('says the PDF is being created and polls with backoff until it is ready', async () => {
        const backend = setup();
        renderApp(`/submissions/${UUID}?submitted=true`);
        expect(await screen.findByText(NOT_READY)).toHaveAttribute('role', 'status');
        expect(backend.state.documentCalls).toBe(1);
        await vi.advanceTimersByTimeAsync(2_000);
        expect(backend.state.documentCalls).toBe(2);
        expect(screen.getByText(NOT_READY)).toBeInTheDocument();
        backend.state.documents.set(UUID, makeDocument());
        await vi.advanceTimersByTimeAsync(3_000);
        expect(backend.state.documentCalls).toBe(2);
        await vi.advanceTimersByTimeAsync(1_000);
        expect(await screen.findByRole('link', { name: /Open PDF/ })).toBeInTheDocument();
        expect(backend.state.documentCalls).toBe(3);
        expect(screen.queryByText(NOT_READY)).not.toBeInTheDocument();
    });

    it('stops polling after the backoff schedule; "Check again" restarts it', async () => {
        const backend = setup();
        const { user } = renderApp(`/submissions/${UUID}`);
        await screen.findByText(NOT_READY);
        await vi.advanceTimersByTimeAsync(200_000);
        expect(backend.state.documentCalls).toBe(7);
        await user.click(screen.getByRole('button', { name: 'Check again' }));
        await vi.waitFor(() => {
            expect(backend.state.documentCalls).toBe(8);
        });
        await vi.advanceTimersByTimeAsync(2_000);
        expect(backend.state.documentCalls).toBe(9);
        backend.state.documents.set(UUID, makeDocument());
        await vi.advanceTimersByTimeAsync(4_000);
        expect(await screen.findByRole('link', { name: /Open PDF/ })).toBeInTheDocument();
        expect(backend.state.documentCalls).toBe(10);
        expect(screen.getByText('The PDF is ready.')).toHaveAttribute('role', 'status');
    });

    it('stops polling when the screen unmounts', async () => {
        const backend = setup();
        const { unmount } = renderApp(`/submissions/${UUID}`);
        await screen.findByText(NOT_READY);
        unmount();
        await vi.advanceTimersByTimeAsync(120_000);
        expect(backend.state.documentCalls).toBe(1);
    });

    it('refreshes the signed link before it expires', async () => {
        const backend = setup();
        backend.state.documents.set(
            UUID,
            makeDocument({ expires_at: new Date(Date.now() + 120_000).toISOString() }),
        );
        renderApp(`/submissions/${UUID}`);
        await screen.findByRole('link', { name: /Open PDF/ });
        await vi.advanceTimersByTimeAsync(61_000);
        expect(backend.state.documentCalls).toBe(2);
    });

    it('keeps refreshing the link after a failed refresh and keeps showing it', async () => {
        const backend = setup();
        let calls = 0;
        server.use(
            http.get('*/api/v1/submissions/:uuid/document', () => {
                calls++;
                return calls === 2
                    ? fail(403, 'forbidden')
                    : ok({
                          document: makeDocument({
                              expires_at: new Date(Date.now() + 120_000).toISOString(),
                          }),
                      });
            }),
        );
        renderApp(`/submissions/${UUID}`);
        await screen.findByRole('link', { name: /Open PDF/ });
        await vi.advanceTimersByTimeAsync(61_000);
        expect(calls).toBe(2);
        expect(screen.getByRole('link', { name: /Open PDF/ })).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        await vi.advanceTimersByTimeAsync(11_000);
        expect(calls).toBe(3);
        expect(backend.state.documentCalls).toBe(0);
    });

    it('refreshes an expiring link when the tab returns to the foreground', async () => {
        const backend = setup();
        backend.state.documents.set(
            UUID,
            makeDocument({ expires_at: new Date(Date.now() + 120_000).toISOString() }),
        );
        renderApp(`/submissions/${UUID}`);
        await screen.findByRole('link', { name: /Open PDF/ });
        focusManager.setFocused(false);
        try {
            await vi.advanceTimersByTimeAsync(90_000);
            expect(backend.state.documentCalls).toBe(1);
            act(() => {
                focusManager.setFocused(true);
            });
            await vi.waitFor(() => {
                expect(backend.state.documentCalls).toBe(2);
            });
        } finally {
            focusManager.setFocused(undefined);
        }
    });

    it('shows a localized error with retry for other failures', async () => {
        const backend = setup();
        let calls = 0;
        server.use(
            http.get('*/api/v1/submissions/:uuid/document', () => {
                calls++;
                return calls === 1 ? fail(403, 'forbidden') : HttpResponse.error();
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('The PDF could not be loaded.');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        // Network failures retry twice (1 s, 2 s) before giving up.
        await vi.advanceTimersByTimeAsync(4_000);
        expect(
            await screen.findByText('You are offline. The PDF can be opened when you are online.'),
        ).toBeInTheDocument();
        expect(calls).toBe(4);
        expect(backend.state.documentCalls).toBe(0);
    });

    it('says so while offline and loads the PDF once the connection is back', async () => {
        const backend = setup();
        backend.state.documents.set(UUID, makeDocument());
        onlineManager.setOnline(false);
        try {
            render(
                <QueryClientProvider client={createAppQueryClient()}>
                    <I18nProvider>
                        <DocumentStatus uuid={UUID} />
                    </I18nProvider>
                </QueryClientProvider>,
            );
            expect(
                screen.getByText('You are offline. The PDF can be opened when you are online.'),
            ).toHaveAttribute('role', 'status');
            expect(backend.state.documentCalls).toBe(0);
            act(() => {
                onlineManager.setOnline(true);
            });
            expect(await screen.findByRole('link', { name: /Open PDF/ })).toBeInTheDocument();
        } finally {
            onlineManager.setOnline(true);
        }
    });

    it('has no document area for drafts', async () => {
        const backend = setup('draft');
        renderApp(`/submissions/${UUID}`);
        expect(await screen.findByRole('heading', { name: 'Service report' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Document (PDF)' })).not.toBeInTheDocument();
        expect(backend.state.documentCalls).toBe(0);
    });
});
