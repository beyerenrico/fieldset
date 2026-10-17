/** Submission detail "Document (PDF)": ready link, polling while rendered, errors. */
import { focusManager, onlineManager, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createAppQueryClient } from '../../src/App.tsx';
import { I18nProvider } from '../../src/i18n/I18nProvider.tsx';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { DocumentStatus } from '../../src/screens/detail/DocumentStatus.tsx';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { makeDocument, makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';
import { FAKE_TIMERS } from '../utils/a-store.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();

const UUID = '0192f1c4-0000-7000-8000-0000000000d1';
const NOT_READY = 'The PDF is being created…';

beforeEach(() => {
    // setImmediate stays real: fake-indexeddb needs it, or Dexie transactions fail (a-store.ts).
    vi.useFakeTimers(FAKE_TIMERS);
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
        const link = await screen.findByRole('link', { name: /Open PDF/ });
        expect(backend.state.documentCalls).toBe(3);
        expect(screen.queryByText(NOT_READY)).not.toBeInTheDocument();
        // Automatic polls never move focus.
        expect(link).not.toHaveFocus();
    });

    it('moves focus to "Open PDF" when a manual check finds the PDF', async () => {
        const backend = setup();
        const { user } = renderApp(`/submissions/${UUID}`);
        await screen.findByText(NOT_READY);
        backend.state.documents.set(UUID, makeDocument());
        await user.click(screen.getByRole('button', { name: 'Check again' }));
        const link = await screen.findByRole('link', { name: /Open PDF/ });
        await vi.waitFor(() => {
            expect(link).toHaveFocus();
        });
        expect(screen.queryByRole('button', { name: 'Check again' })).not.toBeInTheDocument();
    });

    it('moves focus to the control that replaces "Try again"', async () => {
        setup();
        let calls = 0;
        server.use(
            http.get('*/api/v1/submissions/:uuid/document', () => {
                calls++;
                return calls === 1 ? fail(403, 'forbidden') : fail(404, 'document_not_ready');
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        await screen.findByRole('alert');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        const check = await screen.findByRole('button', { name: 'Check again' });
        await vi.waitFor(() => {
            expect(check).toHaveFocus();
        });
    });

    it('shows a permanent render failure, stops polling and offers a manual check', async () => {
        setup();
        let calls = 0;
        server.use(
            http.get('*/api/v1/submissions/:uuid/document', () => {
                calls++;
                return fail(409, 'document_failed', null, 'The PDF exceeds the size limit.');
            }),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('The PDF exceeds the size limit.');
        expect(screen.queryByText(NOT_READY)).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Open PDF/ })).not.toBeInTheDocument();
        await vi.advanceTimersByTimeAsync(200_000);
        expect(calls).toBe(1);
        const check = within(alert).getByRole('button', { name: 'Check again' });
        await user.click(check);
        await vi.waitFor(() => {
            expect(calls).toBe(2);
        });
        await vi.advanceTimersByTimeAsync(200_000);
        expect(calls).toBe(2);
        // Focus moves in an effect after the check settles (DocumentStatus); the status block may
        // re-render with a new button, so query the control again instead of holding the old node.
        await vi.waitFor(() => {
            expect(screen.getByRole('button', { name: 'Check again' })).toHaveFocus();
        });
    });

    it('falls back to a localized failure text and shows the PDF once it exists', async () => {
        setup();
        let failed = true;
        server.use(
            http.get('*/api/v1/submissions/:uuid/document', () =>
                failed ? fail(409, 'document_failed', null, '') : ok({ document: makeDocument() }),
            ),
        );
        const { user } = renderApp(`/submissions/${UUID}`);
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'The PDF could not be created. The office has been notified.',
        );
        failed = false;
        await user.click(screen.getByRole('button', { name: 'Check again' }));
        const link = await screen.findByRole('link', { name: /Open PDF/ });
        await vi.waitFor(() => {
            expect(link).toHaveFocus();
        });
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('shows that the PDF files were deleted on anonymization as a notice and never polls', async () => {
        setup();
        let calls = 0;
        server.use(
            http.get('*/api/v1/submissions/:uuid/document', () => {
                calls++;
                return fail(410, 'document_anonymized', null, 'The PDF files are gone for good.');
            }),
        );
        renderApp(`/submissions/${UUID}`);
        expect(await screen.findByText('The PDF files are gone for good.')).toHaveAttribute(
            'role',
            'status',
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Check again|Try again/ })).toBeNull();
        expect(screen.queryByRole('link', { name: /Open PDF/ })).not.toBeInTheDocument();
        await vi.advanceTimersByTimeAsync(200_000);
        expect(calls).toBe(1);
    });

    it('falls back to a localized text when an anonymized submission comes without one', async () => {
        setup();
        server.use(
            http.get('*/api/v1/submissions/:uuid/document', () =>
                fail(410, 'document_anonymized', null, ''),
            ),
        );
        renderApp(`/submissions/${UUID}`);
        expect(
            await screen.findByText(
                'The PDF files were deleted when this submission was anonymized.',
            ),
        ).toHaveAttribute('role', 'status');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
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
