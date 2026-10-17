import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { getFavorites, recordRecent, toggleFavorite } from '../../src/lib/forms/shortcuts.ts';
import { UUID_V7 } from '../../src/lib/ids.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { db } from '../../src/lib/db/db.ts';
import { enqueueSubmit } from '../../src/lib/outbox/outbox.ts';
import { SCOPE, draftRow, setupSyncScope } from '../utils/a-store.ts';
import { createBackend, fail } from '../utils/backend.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();
preloadLazyScreens();
setupSyncScope();

beforeEach(() => {
    setOrganizationId(1);
});

describe('home', () => {
    it('lists drafts (incl. changes requested) and links to fill', async () => {
        const backend = createBackend();
        backend.state.submissions.set(
            'd1',
            makeSubmission({ uuid: 'd1', updated_at: '2026-10-15T08:00:00Z' }),
        );
        backend.state.submissions.set(
            'd2',
            makeSubmission({
                uuid: 'd2',
                form_id: 4,
                status: 'changes_requested',
                updated_at: '2026-10-16T08:00:00Z',
            }),
        );
        backend.state.submissions.set('s1', makeSubmission({ uuid: 's1', status: 'submitted' }));
        server.use(...backend.handlers);
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        const links = await within(drafts).findAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/app/fill/d2',
            '/app/fill/d1',
        ]);
        expect(links[0]).toHaveTextContent('Inspection');
        expect(links[0]).toHaveTextContent('Changes requested');
    });

    it("lists only drafts the user may edit, not other people's visible submissions", async () => {
        const backend = createBackend();
        backend.state.submissions.set('mine', makeSubmission({ uuid: 'mine' }));
        backend.state.submissions.set(
            'theirs-draft',
            makeSubmission({ uuid: 'theirs-draft', can_edit: false }),
        );
        backend.state.submissions.set(
            'theirs-changes',
            makeSubmission({
                uuid: 'theirs-changes',
                status: 'changes_requested',
                can_edit: false,
                review: { outcome: 'rejected', comment: 'Fix it', by: null, at: null },
            }),
        );
        server.use(...backend.handlers);
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        const links = await within(drafts).findAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual(['/app/fill/mine']);
        expect(drafts).not.toHaveTextContent('Edit and resubmit');
        expect(drafts).not.toHaveTextContent('Fix it');
    });

    it('shows a reviewer no drafts when every visible one belongs to someone else', async () => {
        const backend = createBackend();
        backend.state.submissions.set(
            'theirs',
            makeSubmission({ uuid: 'theirs', status: 'changes_requested', can_edit: false }),
        );
        server.use(...backend.handlers);
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        expect(await within(drafts).findByText('No open drafts.')).toBeInTheDocument();
        expect(within(drafts).queryByRole('link')).not.toBeInTheDocument();
    });

    it('shows favorites and recent forms, purged by fillable_form_ids, and starts a draft', async () => {
        toggleFavorite(1, 4);
        toggleFavorite(1, 99);
        recordRecent(1, 5);
        const backend = createBackend({ fillable: [3, 4, 5] });
        server.use(...backend.handlers);
        const { user, history } = renderApp('/');
        expect(await screen.findByText('No open drafts.')).toBeInTheDocument();
        const start = await screen.findByRole('button', { name: 'Start Inspection' });
        expect(screen.getByRole('button', { name: 'Start Timesheet' })).toBeInTheDocument();
        expect(getFavorites(1)).toEqual([4]);
        await user.click(start);
        await screen.findByRole('heading', { name: 'Service report' });
        const [, , , uuid] = history.location.pathname.split('/');
        expect(uuid).toMatch(UUID_V7);
        expect(history.location.search).toBe('?formId=4&versionId=8');
    });

    it('explains empty shortcuts and retries failed drafts', async () => {
        server.use(...createBackend().handlers);
        server.use(http.get('*/api/v1/submissions', () => fail(403, 'forbidden')));
        renderApp('/');
        expect(await screen.findByText(/Mark forms as favorites/)).toBeInTheDocument();
        expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });
});

describe('drafts on this device (ADR 0035)', () => {
    const NOW = Date.parse('2026-10-17T08:00:00Z');

    /** l1: only here; d1: also on the server, in conflict; q1: submit queued ("number pending"). */
    async function seed(): Promise<void> {
        await db.drafts.bulkPut([
            draftRow({ uuid: 'l1', formId: 4, updatedAt: NOW, sync: 'queued' }),
            draftRow({
                uuid: 'd1',
                serverRevision: 1,
                server: makeSubmission({ uuid: 'd1' }),
                updatedAt: NOW - 60_000,
                sync: 'conflict',
                conflict: makeSubmission({ uuid: 'd1', revision: 2 }),
            }),
            draftRow({ uuid: 'q1', formId: 5, updatedAt: NOW - 120_000 }),
        ]);
        await enqueueSubmit(SCOPE, 'q1', '2026-10-17T07:58:00.000Z');
    }

    it('merges them into "Continue drafts" with their sync state; queued submits are no drafts', async () => {
        await seed();
        const backend = createBackend();
        backend.state.submissions.set(
            'd1',
            makeSubmission({ uuid: 'd1', updated_at: '2026-10-15T08:00:00Z' }),
        );
        backend.state.submissions.set('q1', makeSubmission({ uuid: 'q1', form_id: 5 }));
        server.use(...backend.handlers);
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        const links = await within(drafts).findAllByRole('link');
        // d1 waits for a decision: it opens the review screen, where its panel is.
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/app/fill/l1',
            '/app/fill/d1/review',
        ]);
        expect(links[0]).toHaveTextContent('Inspection');
        expect(links[0]).toHaveTextContent('Waiting to sync');
        expect(links[1]).toHaveTextContent('Conflict');
    });

    it('still lists them when the server cannot be reached', async () => {
        await seed();
        server.use(...createBackend().handlers);
        server.use(http.get('*/api/v1/submissions', () => HttpResponse.error()));
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        expect(
            await within(drafts).findByText('Offline: showing the drafts on this device.'),
        ).toBeInTheDocument();
        expect(within(drafts).getAllByRole('link')).toHaveLength(2);
    });

    it('shows unsynced items with "number pending" on My submissions', async () => {
        await seed();
        server.use(...createBackend().handlers);
        renderApp('/submissions');
        const local = await screen.findByRole('region', { name: 'On this device' });
        const links = within(local).getAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/app/fill/l1',
            '/app/fill/d1/review',
            '/app/submissions/q1',
        ]);
        expect(links[2]).toHaveTextContent('Number pending');
        expect(links[2]).toHaveTextContent('Waiting to sync');
        expect(links[2]).toHaveTextContent('Timesheet');
    });
});

describe('catalog', () => {
    it('searches, filters by category, toggles favorites and starts a form', async () => {
        server.use(...createBackend().handlers);
        const { user, history } = renderApp('/forms');
        const list = await screen.findAllByRole('button', { name: /^Start / });
        expect(list).toHaveLength(3);
        await user.type(screen.getByLabelText('Search forms'), 'insp');
        expect(screen.getAllByRole('button', { name: /^Start / })).toHaveLength(1);
        await user.clear(screen.getByLabelText('Search forms'));
        await user.click(screen.getByRole('button', { name: 'Other' }));
        expect(screen.getByRole('button', { name: 'Other' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(
            screen.getAllByRole('button', { name: /^Start / }).map((b) => b.textContent),
        ).toEqual(['Timesheet']);
        await user.click(screen.getByRole('button', { name: 'All' }));
        await user.type(screen.getByLabelText('Search forms'), 'zzz');
        expect(screen.getByText('No forms found.')).toBeInTheDocument();
        await user.clear(screen.getByLabelText('Search forms'));
        const star = screen.getByRole('button', { name: 'Add Service report to favorites' });
        await user.click(star);
        expect(
            screen.getByRole('button', { name: 'Remove Service report from favorites' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(getFavorites(1)).toEqual([3]);
        await user.click(screen.getByRole('button', { name: 'Start Service report' }));
        await screen.findByRole('heading', { name: 'Service report' });
        expect(history.location.pathname).toMatch(/^\/app\/fill\//);
    });

    it('shows an error with retry', async () => {
        server.use(...createBackend().handlers);
        server.use(http.get('*/api/v1/forms', () => fail(403, 'forbidden')));
        renderApp('/forms');
        expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });
});
