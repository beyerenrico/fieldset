/** "Changes requested" (§6.5): reviewer comment on detail + fill, edit and resubmit (number kept). */
import { screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReviewDecision } from '../../src/lib/api/types.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { setupSyncScope } from '../utils/a-store.ts';
import { createBackend } from '../utils/backend.ts';
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
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000cc';
const REVIEW: ReviewDecision = {
    outcome: 'rejected',
    comment: 'Bitte Seriennummer ergänzen',
    by: { id: 4, name: 'Rita Reviewer' },
    at: '2026-10-16T11:00:00+02:00',
};

beforeEach(() => {
    setOrganizationId(1);
});

function setup(review: ReviewDecision | null = REVIEW) {
    const backend = createBackend();
    backend.state.submissions.set(
        UUID,
        makeSubmission({
            uuid: UUID,
            status: 'changes_requested',
            number: 'SR-2026-00007',
            revision: 5,
            submitted_at: '2026-10-16T09:41:00+02:00',
            review,
            open_task_id: null,
        }),
    );
    server.use(...backend.handlers);
    return backend;
}

describe('changes requested', () => {
    it('shows the reviewer comment on the detail with an "Edit and resubmit" action', async () => {
        setup();
        const { user, history } = renderApp(`/submissions/${UUID}`);
        const note = await screen.findByRole('region', { name: 'Changes requested' });
        expect(note).toHaveTextContent('Bitte Seriennummer ergänzen');
        expect(note).toHaveTextContent(/Rita Reviewer on Oct 16, 2026/);
        expect(screen.getByText('Number SR-2026-00007')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Continue editing' })).not.toBeInTheDocument();
        const action = within(note).getByRole('link', { name: 'Edit and resubmit' });
        expect(action).toHaveAttribute('href', `/app/fill/${UUID}`);
        await user.click(action);
        // The fill screen (its primary action), not the detail that is still on screen meanwhile.
        await screen.findByRole('button', { name: 'Review' });
        expect(history.location.pathname).toBe(`/app/fill/${UUID}`);
        // The comment stays visible while editing.
        expect(screen.getByRole('region', { name: 'Changes requested' })).toHaveTextContent(
            'Bitte Seriennummer ergänzen',
        );
    });

    it('shows the note read-only to someone who may not edit (e.g. the reviewer)', async () => {
        const backend = setup();
        backend.state.submissions.set(UUID, {
            ...backend.state.submissions.get(UUID)!,
            can_edit: false,
        });
        renderApp(`/submissions/${UUID}`);
        const note = await screen.findByRole('region', { name: 'Changes requested' });
        expect(note).toHaveTextContent('Bitte Seriennummer ergänzen');
        expect(within(note).queryByRole('link')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Edit and resubmit' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Continue editing' })).not.toBeInTheDocument();
    });

    it('names an unknown reviewer and a missing date neutrally', async () => {
        setup({ ...REVIEW, by: null, at: null });
        renderApp(`/submissions/${UUID}`);
        const note = await screen.findByRole('region', { name: 'Changes requested' });
        expect(note).toHaveTextContent('Reviewer');
        expect(note).not.toHaveTextContent(' on ');
    });

    it('resubmits with the current revision and keeps the number', async () => {
        const backend = setup();
        const { user, history } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(screen.getByText('The number SR-2026-00007 is kept.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Submit' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Resubmit' }));
        expect(await screen.findByText('Resubmitted', { selector: 'p' })).toBeInTheDocument();
        expect(screen.getByText('Number SR-2026-00007', { selector: 'p' })).toBeInTheDocument();
        expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        expect(backend.state.submits).toHaveLength(1);
        // The persisted submit body also carries the device time (ADR 0035).
        expect(backend.state.submits[0]?.body).toEqual({
            revision: 5,
            client_submitted_at: expect.stringMatching(/^\d{4}-\d\d-\d\dT/) as unknown,
        });
        expect(backend.state.submits[0]?.key).toMatch(/^[0-9a-f-]{36}$/);
        await waitFor(() => {
            expect(screen.queryByRole('region', { name: 'Changes requested' })).toBeNull();
        });
        expect(screen.getByText('Submitted', { selector: 'span' })).toBeInTheDocument();
    });

    it('says "Erneut einreichen" in German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        setup();
        renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Prüfen und unterschreiben' });
        expect(screen.getByRole('button', { name: 'Erneut einreichen' })).toBeInTheDocument();
        expect(screen.getByText('Die Nummer SR-2026-00007 bleibt erhalten.')).toBeInTheDocument();
    });
});
