/**
 * Connection state and unsynced counts without flicker (F3 gate fixes 7 and 8): "lie-fi" says
 * "No connection"; online, a pending count waits 10 s with no sync running (header badge and iOS
 * warning alike), offline it shows at once.
 */
import { act, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { OnlineIndicator } from '../../src/components/OnlineIndicator.tsx';
import { InstallPrompt } from '../../src/components/pwa/InstallPrompt.tsx';
import { PENDING_GRACE_MS } from '../../src/components/sync/usePendingVisible.ts';
import { db, setMeta } from '../../src/lib/db/db.ts';
import type { SyncState } from '../../src/lib/db/schema.ts';
import { setCurrentUserId } from '../../src/lib/db/scope.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { FAKE_TIMERS } from '../utils/a-store.ts';
import { attachmentRow } from '../utils/b-attachments.ts';
import { IPHONE, renderWithI18n } from '../utils/b-pwa.tsx';
import { createBackend } from '../utils/backend.ts';
import { seedUnsynced, setOnline } from '../utils/c-rows.ts';
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
afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    setCurrentUserId(null);
});

/** Small steps: IndexedDB (liveQuery) answers in real time between them. */
async function advance(ms: number, step = 250): Promise<void> {
    for (let passed = 0; passed < ms; passed += step) {
        await vi.advanceTimersByTimeAsync(Math.min(step, ms - passed));
    }
}

const syncState = (state: SyncState) =>
    setMeta('sync', { state, lastRunAt: 1, lastSuccessAt: null, lastError: null });

describe('connection state', () => {
    it('says "No connection" when the browser is online but the server was unreachable', async () => {
        renderWithI18n(<OnlineIndicator />);
        expect(screen.getByRole('status')).toHaveTextContent('Online');
        await act(() => syncState('offline'));
        expect(await screen.findByText('No connection')).toHaveAttribute('role', 'status');
        await act(() => syncState('idle'));
        expect(await screen.findByText('Online')).toBeInTheDocument();
        setOnline(false);
        await act(() => syncState('offline'));
        expect(await screen.findByText('Offline')).toBeInTheDocument();
    });

    it('says "Keine Verbindung" in German', async () => {
        writeJson('settings', { language: 'de' });
        await syncState('offline');
        renderWithI18n(<OnlineIndicator />);
        expect(await screen.findByText('Keine Verbindung')).toBeInTheDocument();
    });
});

describe('pending counts online', () => {
    it('shows the header count after 10 s pending, never while a sync runs', async () => {
        vi.useFakeTimers(FAKE_TIMERS);
        server.use(...createBackend().handlers);
        renderApp('/settings');
        await screen.findByRole('heading', { name: 'Settings' });
        await seedUnsynced();
        await advance(2000);
        expect(screen.queryByRole('link', { name: /not synced/ })).not.toBeInTheDocument();

        await syncState('running');
        await advance(PENDING_GRACE_MS);
        expect(screen.queryByRole('link', { name: /not synced/ })).not.toBeInTheDocument();

        await syncState('idle');
        expect(
            await screen.findByRole('link', { name: 'Submissions not synced: 1' }),
        ).toBeInTheDocument();

        // Synced, then the next autosave: the clock starts over.
        await db.transaction('rw', db.drafts, db.outbox, db.attachments, async () => {
            await Promise.all([db.drafts.clear(), db.outbox.clear(), db.attachments.clear()]);
        });
        await advance(500);
        await seedUnsynced();
        await advance(1000);
        expect(screen.queryByRole('link', { name: /not synced/ })).not.toBeInTheDocument();
    });

    it('keeps the iOS install text while autosaves come and go, then warns after 10 s', async () => {
        vi.useFakeTimers(FAKE_TIMERS);
        setCurrentUserId(7);
        renderWithI18n(<InstallPrompt platform={IPHONE} />);
        expect(await screen.findByText(/then “Add to Home Screen”/)).toBeInTheDocument();
        await db.attachments.put(attachmentRow());
        await advance(PENDING_GRACE_MS / 2);
        expect(screen.queryByText(/not synced yet/)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Not now' })).toBeInTheDocument();

        await advance(PENDING_GRACE_MS / 2 + 500);
        expect(await screen.findByText(/Submissions not synced yet: 1\./)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Not now' })).not.toBeInTheDocument();
    });
});
