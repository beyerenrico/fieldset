/** Polling fallback (§8.9): the bell and open tasks refresh every minute while the tab is visible. */
import { act, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { POLL_INTERVAL_MS } from '../../src/hooks/queries.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { createBackend } from '../utils/backend.ts';
import { makeNotification } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';
import { resetVisibilityAfterEach, setVisibility } from '../utils/visibility.ts';
import { FAKE_TIMERS } from '../utils/a-store.ts';

setupTestServer();
resetVisibilityAfterEach();

beforeEach(() => {
    setOrganizationId(1);
    // setImmediate stays real: fake-indexeddb needs it, or Dexie transactions fail (a-store.ts).
    vi.useFakeTimers(FAKE_TIMERS);
});

afterEach(() => {
    vi.useRealTimers();
});

const advance = async (ms: number): Promise<void> => {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
};

describe('notification polling', () => {
    it('polls every minute while visible, pauses while hidden, refetches on focus, stops on unmount', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        const { unmount } = renderApp('/');
        await screen.findByRole('link', { name: 'Notifications' });
        await waitFor(() => {
            expect(backend.state.notificationCalls).toBe(1);
        });
        expect(backend.state.taskCalls).toHaveLength(1);

        backend.state.notifications = [makeNotification()];
        await advance(POLL_INTERVAL_MS);
        expect(
            await screen.findByRole('link', { name: 'Notifications, 1 unread' }),
        ).toBeInTheDocument();
        expect(backend.state.notificationCalls).toBe(2);
        expect(backend.state.taskCalls).toHaveLength(2);

        act(() => {
            setVisibility('hidden');
        });
        await advance(3 * POLL_INTERVAL_MS);
        expect(backend.state.notificationCalls).toBe(2);
        expect(backend.state.taskCalls).toHaveLength(2);

        act(() => {
            setVisibility('visible');
        });
        await waitFor(() => {
            expect(backend.state.notificationCalls).toBe(3);
        });

        unmount();
        await advance(3 * POLL_INTERVAL_MS);
        expect(backend.state.notificationCalls).toBe(3);
    });
});
