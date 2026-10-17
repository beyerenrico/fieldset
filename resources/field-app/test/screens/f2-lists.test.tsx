/**
 * Home and My submissions with paused items and without a connection (UX review, ADR 0035): a
 * refused submit is listed with what to fix and opens the review screen, offline is no error, and
 * an item on this device is not listed twice (the status filter applies to it too).
 */
import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { draftRow, setupSyncScope } from '../utils/a-store.ts';
import { createBackend } from '../utils/backend.ts';
import { outboxError, putOps, submitOp, writesOffline } from '../utils/f2-rows.ts';
import { makeSubmission } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();
setupSyncScope();

const REFUSED =
    'Not submitted: 2 fields need fixing. Open the form, correct the marked fields and submit again.';

beforeEach(() => {
    setOrganizationId(1);
});

/** r1: submit refused (op blocked); q1: submit queued; l1: unsynced edits of a server draft. */
async function seed(): Promise<void> {
    await db.drafts.bulkPut([
        draftRow({
            uuid: 'r1',
            formId: 4,
            sync: 'blocked',
            blockedBy: 'submit_refused',
            submitErrors: [
                { pointer: '/a', code: 'required' },
                { pointer: '/b', code: 'required' },
            ],
            updatedAt: 3,
        }),
        draftRow({ uuid: 'q1', formId: 5, updatedAt: 2 }),
        draftRow({
            uuid: 'l1',
            serverRevision: 1,
            server: makeSubmission({ uuid: 'l1' }),
            updatedAt: 1,
        }),
    ]);
    await putOps(
        submitOp('r1', { state: 'blocked', lastError: outboxError(422, 'validation_failed') }),
        submitOp('q1', { seq: 4 }),
    );
}

function backendWith(...submissions: ReturnType<typeof makeSubmission>[]) {
    const backend = createBackend();
    for (const submission of submissions) {
        backend.state.submissions.set(submission.uuid, submission);
    }
    server.use(...backend.handlers);
    writesOffline();
    return backend;
}

describe('home', () => {
    it('lists a refused submit as a draft again, with what to fix, opening the review', async () => {
        await seed();
        backendWith(makeSubmission({ uuid: 'l1' }), makeSubmission({ uuid: 'q1', form_id: 5 }));
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        const refused = await within(drafts).findByRole('link', { name: /Inspection/ });
        expect(refused).toHaveAttribute('href', '/app/fill/r1/review');
        expect(refused).toHaveTextContent(REFUSED);
        expect(refused).toHaveTextContent('Needs your decision');
        // The queued submit is no draft.
        expect(within(drafts).queryByRole('link', { name: /Timesheet/ })).toBeNull();
    });

    it('says offline without an error when nothing is stored on this device', async () => {
        backendWith();
        server.use(http.get('*/api/v1/submissions', () => HttpResponse.error()));
        renderApp('/');
        const drafts = await screen.findByRole('region', { name: 'Continue drafts' });
        expect(
            await within(drafts).findByText('Offline – no drafts are stored on this device.'),
        ).toHaveClass('text-muted-foreground');
        expect(within(drafts).queryByRole('alert')).toBeNull();
        expect(within(drafts).queryByRole('button', { name: 'Try again' })).toBeNull();
    });
});

describe('my submissions', () => {
    it('says offline without an error and still lists the items on this device', async () => {
        await seed();
        backendWith();
        server.use(http.get('*/api/v1/submissions', () => HttpResponse.error()));
        renderApp('/submissions');
        expect(
            await screen.findByText('Offline – the full list loads when you are online.'),
        ).toHaveClass('text-muted-foreground');
        expect(screen.queryByRole('alert')).toBeNull();
        const local = screen.getByRole('region', { name: 'On this device' });
        expect(within(local).getAllByRole('link')).toHaveLength(3);
    });

    it('lists an item on this device once and links a refused submit to the review', async () => {
        await seed();
        backendWith(
            makeSubmission({ uuid: 'l1' }),
            makeSubmission({ uuid: 's1', status: 'submitted', number: 'SR-2026-00040' }),
        );
        renderApp('/submissions');
        const local = await screen.findByRole('region', { name: 'On this device' });
        const s1 = await screen.findByRole('link', { name: /SR-2026-00040/ });
        const all = screen.getAllByRole('link').map((link) => link.getAttribute('href'));
        expect(all.filter((href) => href?.includes('l1'))).toEqual(['/app/fill/l1']);
        expect(within(local).getByRole('link', { name: /Service report/ })).toHaveAttribute(
            'href',
            '/app/fill/l1',
        );
        expect(s1).toHaveAttribute('href', '/app/submissions/s1');
        const refused = within(local).getByRole('link', { name: /Inspection/ });
        expect(refused).toHaveAttribute('href', '/app/fill/r1/review');
        expect(refused).toHaveTextContent(REFUSED);
    });

    it('applies the status filter to the items on this device', async () => {
        await seed();
        backendWith(
            makeSubmission({ uuid: 'l1' }),
            makeSubmission({ uuid: 's1', status: 'submitted', number: 'SR-2026-00040' }),
        );
        renderApp('/submissions?status=submitted');
        await screen.findByRole('link', { name: /SR-2026-00040/ });
        const local = await screen.findByRole('region', { name: 'On this device' });
        // q1 is on its way to "submitted"; the drafts r1 and l1 are filtered out.
        expect(
            within(local)
                .getAllByRole('link')
                .map((l) => l.getAttribute('href')),
        ).toEqual(['/app/submissions/q1']);
    });
});
