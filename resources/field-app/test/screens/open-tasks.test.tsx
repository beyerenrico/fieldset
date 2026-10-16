/** Home (§9.1): open review tasks and the changes-requested note in "Continue drafts". */
import { act, screen, waitFor, within } from '@testing-library/react';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { makeSubmission, makeTask } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';
import { resetVisibilityAfterEach, setVisibility } from '../utils/visibility.ts';

setupTestServer();
preloadLazyScreens();
resetVisibilityAfterEach();

beforeEach(() => {
    setOrganizationId(1);
});

const OVERDUE = makeTask({
    id: 42,
    overdue: true,
    due_at: '2026-10-14T12:00:00+02:00',
    submission: {
        ...makeTask().submission,
        uuid: 'u-overdue',
        number: 'IN-2026-00003',
        form: { id: 4, name: 'Inspection' },
    },
});

describe('home: open tasks', () => {
    it('lists open tasks with form, number, submitter and due date, linking to the detail', async () => {
        const backend = createBackend({ tasks: [makeTask(), OVERDUE] });
        server.use(...backend.handlers);
        renderApp('/');
        const section = await screen.findByRole('region', { name: 'Open tasks' });
        const links = within(section).getAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/app/submissions/0192f1c4-0000-7000-8000-0000000000a1',
            '/app/submissions/u-overdue',
        ]);
        expect(links[0]).toHaveTextContent('Review: Service report');
        expect(links[0]).toHaveTextContent('No. SR-2026-00012');
        expect(links[0]).toHaveTextContent('Submitted by Susi Submitter');
        expect(links[0]).toHaveTextContent(/Due Oct 20, 2026/);
        expect(links[0]).not.toHaveTextContent('Overdue');
        // Overdue is said in words, not only with colour.
        expect(links[1]).toHaveTextContent(/Overdue – was due Oct 14, 2026/);
        expect(backend.state.taskCalls).toEqual(['open']);
    });

    it('is hidden when there are no open tasks', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        renderApp('/');
        await screen.findByText('No open drafts.');
        await waitFor(() => {
            expect(backend.state.taskCalls).toHaveLength(1);
        });
        expect(screen.queryByRole('region', { name: 'Open tasks' })).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Open tasks' })).not.toBeInTheDocument();
    });

    it('is hidden when the task list cannot be loaded (e.g. an older server)', async () => {
        let calls = 0;
        server.use(...createBackend().handlers);
        server.use(
            http.get('*/api/v1/tasks', () => {
                calls++;
                return fail(404, 'not_found');
            }),
        );
        renderApp('/');
        await screen.findByText('No open drafts.');
        await waitFor(() => {
            expect(calls).toBe(1);
        });
        expect(screen.queryByRole('region', { name: 'Open tasks' })).not.toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('leaves out tasks that are no longer open (cancelled or completed meanwhile)', async () => {
        server.use(...createBackend().handlers);
        server.use(
            http.get('*/api/v1/tasks', () =>
                ok(
                    [
                        makeTask({ id: 1, status: 'cancelled' }),
                        OVERDUE,
                        makeTask({ id: 2, status: 'completed', outcome: 'approved' }),
                    ],
                    {},
                    { current_page: 1, last_page: 1, total: 3 },
                ),
            ),
        );
        renderApp('/');
        const section = await screen.findByRole('region', { name: 'Open tasks' });
        const links = within(section).getAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/app/submissions/u-overdue',
        ]);
    });

    it('omits a missing number, submitter and due date', async () => {
        const task = makeTask({
            due_at: null,
            submission: { ...makeTask().submission, number: null, submitted_by: null },
        });
        server.use(...createBackend({ tasks: [task] }).handlers);
        renderApp('/');
        const section = await screen.findByRole('region', { name: 'Open tasks' });
        const link = within(section).getByRole('link');
        expect(link).toHaveTextContent('Review: Service report');
        expect(link).not.toHaveTextContent(/No\.|Submitted by|Due/);
    });

    it('refreshes when the tab becomes visible again', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        renderApp('/');
        await waitFor(() => {
            expect(backend.state.taskCalls).toHaveLength(1);
        });
        backend.state.tasks = [makeTask()];
        act(() => {
            setVisibility('hidden');
        });
        act(() => {
            setVisibility('visible');
        });
        expect(await screen.findByRole('region', { name: 'Open tasks' })).toBeInTheDocument();
        expect(backend.state.taskCalls.length).toBeGreaterThanOrEqual(2);
    });

    it('speaks German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        server.use(...createBackend({ tasks: [OVERDUE] }).handlers);
        renderApp('/');
        const section = await screen.findByRole('region', { name: 'Offene Aufgaben' });
        const link = within(section).getByRole('link');
        expect(link).toHaveTextContent('Prüfen: Inspection');
        expect(link).toHaveTextContent('Nr. IN-2026-00003');
        expect(link).toHaveTextContent('Eingereicht von Susi Submitter');
        expect(link).toHaveTextContent(/Überfällig – war fällig am 14\.10\.2026/);
    });
});

describe('home: changes requested', () => {
    it('shows the reviewer comment and the resubmit action in the draft entry', async () => {
        const backend = createBackend();
        backend.state.submissions.set(
            'd2',
            makeSubmission({
                uuid: 'd2',
                status: 'changes_requested',
                number: 'SR-2026-00007',
                review: {
                    outcome: 'rejected',
                    comment: 'Bitte Seriennummer ergänzen',
                    by: { id: 4, name: 'Rita Reviewer' },
                    at: '2026-10-16T11:00:00+02:00',
                },
            }),
        );
        server.use(...backend.handlers);
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        const link = await within(drafts).findByRole('link');
        expect(link).toHaveAttribute('href', '/app/fill/d2');
        expect(link).toHaveTextContent('Bitte Seriennummer ergänzen');
        expect(link).toHaveTextContent(/Rita Reviewer on Oct 16, 2026/);
        expect(link).toHaveTextContent('Edit and resubmit');
    });

    it('explains a missing comment', async () => {
        const backend = createBackend();
        backend.state.submissions.set(
            'd3',
            makeSubmission({ uuid: 'd3', status: 'changes_requested', review: null }),
        );
        server.use(...backend.handlers);
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        const link = await within(drafts).findByRole('link');
        expect(link).toHaveTextContent('No comment was given.');
    });
});
