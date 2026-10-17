/** Header sync badge: submissions not synced, decisions needed, sign in again (ADR 0035, M6-T07). */
import { screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { db, setMeta } from '../../src/lib/db/db.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createBackend } from '../utils/backend.ts';
import { draftRow, saveOp, seedUnsynced } from '../utils/c-rows.ts';
import { ORG_A, ORG_B, makeMe } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('../../src/lib/sync/engine.ts', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../../src/lib/sync/engine.ts')>()),
    startSyncEngine: () => () => undefined,
}));

setupTestServer();

beforeEach(() => {
    setOrganizationId(1);
});

const header = (): HTMLElement => {
    const element = document.querySelector('header');
    if (element === null) {
        throw new Error('no header');
    }
    return element;
};

describe('sync status badge', () => {
    it('is hidden while everything is synced and counts unsynced submissions live', async () => {
        server.use(...createBackend({ me: makeMe([ORG_A, ORG_B]) }).handlers);
        renderApp('/settings');
        await screen.findByRole('heading', { name: 'Settings' });
        expect(screen.queryByRole('link', { name: /not synced/ })).not.toBeInTheDocument();

        await seedUnsynced({ orgId: 1, userId: 7 }, 'a');
        await seedUnsynced({ orgId: 2, userId: 7 }, 'b'); // other organization of the same user
        await seedUnsynced({ orgId: 1, userId: 99 }, 'c'); // another user: never counted
        // Each seeded submission has a dirty draft, its op and a photo: one submission, not three.
        const badge = await screen.findByRole('link', { name: 'Submissions not synced: 2' });
        expect(badge).toHaveAttribute('href', '/app/settings');
        expect(header()).toContainElement(badge);
        expect(badge).toHaveTextContent('2');

        await db.outbox.clear();
        await db.attachments.clear();
        await db.drafts.clear();
        await waitFor(() => {
            expect(screen.queryByRole('link', { name: /not synced/ })).not.toBeInTheDocument();
        });
    });

    it('points to the submissions when items need a decision', async () => {
        server.use(...createBackend().handlers);
        await db.drafts.put(draftRow('x', { sync: 'conflict', localVersion: 1, syncedVersion: 1 }));
        await db.outbox.put(saveOp('x', { state: 'blocked' }));
        renderApp('/settings');
        const badge = await screen.findByRole('link', { name: 'Need your decision: 2' });
        expect(badge).toHaveAttribute('href', '/app/submissions');
    });

    it('asks to sign in again while the engine waits for a new session', async () => {
        server.use(...createBackend().handlers);
        await seedUnsynced();
        await setMeta('sync', {
            state: 'reauth',
            lastRunAt: 1,
            lastSuccessAt: null,
            lastError: null,
        });
        renderApp('/settings');
        const badge = await screen.findByRole('link', { name: 'Sign in again to sync' });
        expect(badge).toHaveAttribute('href', '/app/settings');
        expect(screen.getByRole('link', { name: 'Sign in again' })).toHaveAttribute(
            'href',
            '/app/login?notice=reauth',
        );
    });

    it('caps the visible number', async () => {
        server.use(...createBackend().handlers);
        await db.outbox.bulkPut(
            Array.from({ length: 120 }, (_, index) => saveOp(`s${String(index)}`)),
        );
        renderApp('/settings');
        const badge = await screen.findByRole('link', { name: 'Submissions not synced: 120' });
        expect(badge).toHaveTextContent('99+');
    });
});
