import { screen, within } from '@testing-library/react';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getDeviceRecord, saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import { getOrganizationId, setOrganizationId } from '../../src/lib/session/org-store.ts';
import { hardReload } from '../../src/lib/session/reload.ts';
import { db, getMeta, setMeta } from '../../src/lib/db/db.ts';
import { runSync } from '../../src/lib/sync/engine.ts';
import { onSyncRequested, type SyncReason } from '../../src/lib/sync/triggers.ts';
import { createBackend, ok } from '../utils/backend.ts';
import { seedUnsynced, stubBrowserCaches, stubStorageManager } from '../utils/c-rows.ts';
import { ORG_A, ORG_B, makeMe, makeSubmission } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

vi.mock('../../src/lib/session/reload.ts', () => ({
    LOGIN_PATH: '/app/login',
    hardReload: vi.fn(),
}));

// The engine is agent A's: these tests drive "Sync now" through a stubbed run.
vi.mock('../../src/lib/sync/engine.ts', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../../src/lib/sync/engine.ts')>()),
    startSyncEngine: () => () => undefined,
    runSync: vi.fn(),
}));

setupTestServer();
preloadLazyScreens();

let restoreBrowser: (() => void)[] = [];

beforeEach(() => {
    setOrganizationId(1);
    vi.mocked(hardReload).mockClear();
    vi.mocked(runSync).mockReset();
});

afterEach(() => {
    for (const restore of restoreBrowser) {
        restore();
    }
    restoreBrowser = [];
});

describe('my submissions', () => {
    it('lists submissions with status, filters and loads more pages', async () => {
        server.use(...createBackend().handlers);
        const pages: Record<string, ReturnType<typeof makeSubmission>[]> = {
            '1': [
                makeSubmission({ uuid: 'a', status: 'submitted', number: 'SR-1', can_edit: false }),
            ],
            '2': [makeSubmission({ uuid: 'b', status: 'draft' })],
        };
        const urls: string[] = [];
        server.use(
            http.get('*/api/v1/submissions', ({ request }) => {
                urls.push(request.url);
                const page = new URL(request.url).searchParams.get('page') ?? '1';
                return ok(
                    pages[page] ?? [],
                    {},
                    { current_page: Number(page), last_page: 2, total: 2 },
                );
            }),
        );
        const { user, history } = renderApp('/submissions');
        const first = await screen.findByRole('link', { name: /SR-1/ });
        expect(first).toHaveAttribute('href', '/app/submissions/a');
        expect(first).toHaveTextContent('Submitted');
        await user.click(screen.getByRole('button', { name: 'Load more' }));
        const draft = await screen.findByRole('link', { name: /No number yet/ });
        expect(draft).toHaveAttribute('href', '/app/fill/b');
        await user.selectOptions(screen.getByLabelText('Status'), 'approved');
        expect(history.location.search).toBe('?status=approved');
        await screen.findByRole('link', { name: /SR-1/ });
        expect(urls.at(-1)).toContain('status=approved');
    });

    it('shows a read-only detail with continue editing for drafts', async () => {
        const backend = createBackend();
        backend.state.submissions.set('d', makeSubmission({ uuid: 'd' }));
        server.use(...backend.handlers);
        renderApp('/submissions/d');
        expect(await screen.findByRole('heading', { name: 'Service report' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Continue editing' })).toHaveAttribute(
            'href',
            '/app/fill/d',
        );
        expect(screen.getByTestId('renderer')).toHaveAttribute('data-readonly', 'true');
        expect(screen.getByText('No number yet')).toBeInTheDocument();
    });

    it('shows "number pending" after submitting without a number', async () => {
        const backend = createBackend();
        backend.state.submissions.set(
            'p',
            makeSubmission({ uuid: 'p', status: 'submitted', can_edit: false }),
        );
        server.use(...backend.handlers);
        renderApp('/submissions/p?submitted=true');
        expect(await screen.findByText('Number pending')).toBeInTheDocument();
    });
});

describe('settings', () => {
    it('switches language, toggles larger text and shows the version', async () => {
        server.use(...createBackend().handlers);
        const { user } = renderApp('/settings');
        expect(await screen.findByRole('heading', { name: 'Settings' })).toBeInTheDocument();
        expect(screen.getByText('Version 0.0.0-test')).toBeInTheDocument();
        await user.click(screen.getByRole('switch', { name: /Larger text/ }));
        expect(document.documentElement).toHaveClass('text-large');
        await user.click(screen.getByRole('radio', { name: 'Deutsch' }));
        expect(await screen.findByRole('heading', { name: 'Einstellungen' })).toBeInTheDocument();
        expect(document.documentElement.lang).toBe('de');
        expect(screen.getByRole('navigation', { name: 'Hauptnavigation' })).toBeInTheDocument();
        await user.click(screen.getByRole('radio', { name: 'English' }));
        await user.click(screen.getByRole('switch', { name: /Larger text/ }));
        expect(document.documentElement).not.toHaveClass('text-large');
    });

    it('switches the organization', async () => {
        server.use(...createBackend({ me: makeMe([ORG_A, ORG_B]) }).handlers);
        const { user } = renderApp('/settings');
        await user.selectOptions(await screen.findByLabelText('Organization'), '2');
        expect(getOrganizationId()).toBe(2);
        expect(
            await screen.findByText('Second AG', { selector: 'header span' }),
        ).toBeInTheDocument();
    });

    it('logs out after confirmation and clears local data (keeps the device id)', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        const browser = stubBrowserCaches();
        restoreBrowser.push(browser.restore);
        await saveDeviceRecord({ deviceUuid: 'dev', secret: 'sec' });
        await setMeta('seq', 7); // stands in for unsynced local data
        const { user, history } = renderApp('/settings');
        await user.click(await screen.findByRole('button', { name: 'Sign out' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Sign out' });
        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await user.click(screen.getByRole('button', { name: 'Sign out' }));
        await user.click(screen.getByRole('button', { name: 'Sign out and remove data' }));
        expect(await screen.findByText('You have been signed out.')).toHaveAttribute(
            'role',
            'status',
        );
        expect(history.location.pathname).toBe('/app/login');
        expect(backend.state.loggedIn).toBe(false);
        expect(await getMeta('seq')).toBeUndefined();
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'dev', secret: null });
        expect(browser.deleted).toEqual(['workbox-precache', 'runtime']);
        expect(browser.unregister).toHaveBeenCalledTimes(1);
        expect(hardReload).toHaveBeenCalledWith('/app/login?notice=logout');
    });

    it('warns about unsynced items before logout and offers "Sync now"', async () => {
        server.use(...createBackend().handlers);
        await seedUnsynced();
        vi.mocked(runSync).mockResolvedValue({
            ran: true,
            sent: 0,
            blocked: 0,
            remaining: 2,
            state: 'error',
        });
        const { user } = renderApp('/settings');
        await screen.findByText('Changes waiting to be sent');
        await user.click(screen.getByRole('button', { name: 'Sign out' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Sign out' });
        expect(
            await within(dialog).findByText('Not synced yet: 3. Signing out deletes these items.'),
        ).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Sync now' }));
        expect(runSync).toHaveBeenCalledWith({ manual: true });
        expect(await within(dialog).findByRole('status')).toHaveTextContent(
            'Sync failed. Your data stays on this device',
        );
        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(await db.drafts.count()).toBe(1);
        expect(hardReload).not.toHaveBeenCalled();
    });

    it('shows the last sync, unsynced counts and storage, and syncs on request', async () => {
        server.use(...createBackend().handlers);
        const storage = stubStorageManager({ persisted: true });
        restoreBrowser.push(storage.restore);
        await seedUnsynced();
        const lastSuccessAt = Date.parse('2026-10-17T08:30:00Z');
        await setMeta('sync', {
            state: 'idle',
            lastRunAt: lastSuccessAt,
            lastSuccessAt,
            lastError: null,
        });
        const reasons: SyncReason[] = [];
        const stop = onSyncRequested((reason) => reasons.push(reason));
        vi.mocked(runSync).mockResolvedValue({
            ran: true,
            sent: 3,
            blocked: 0,
            remaining: 0,
            state: 'idle',
        });
        const { user } = renderApp('/settings');
        expect(await screen.findByText(/^Last synced: /)).toBeInTheDocument();
        const label = await screen.findByText('Drafts not synced');
        const counts = within(label.closest('dl') as HTMLElement);
        expect(counts.getAllByRole('definition').map((cell) => cell.textContent)).toEqual([
            '1',
            '1',
            '1',
            '0',
        ]);
        expect(await screen.findByText('12.5 MB of 2 GB used')).toBeInTheDocument();
        expect(
            screen.getByText('The browser keeps this data (persistent storage).'),
        ).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Sync now' }));
        expect(await screen.findByText('Everything is synced.')).toHaveAttribute('role', 'status');
        expect(runSync).toHaveBeenCalledWith({ manual: true });
        expect(reasons).toContain('manual');
        stop();
    });

    it.each([
        [
            { ran: false, sent: 0, blocked: 0, remaining: 0, state: 'running' },
            'A sync is already running.',
        ],
        [{ ran: true, sent: 0, blocked: 0, remaining: 1, state: 'offline' }, 'You are offline.'],
        [
            { ran: true, sent: 0, blocked: 0, remaining: 1, state: 'reauth' },
            'Sign in again to sync.',
        ],
        [
            { ran: true, sent: 1, blocked: 2, remaining: 2, state: 'idle' },
            'before they can sync: 2',
        ],
        [
            { ran: true, sent: 1, blocked: 0, remaining: 4, state: 'idle' },
            'Still waiting to sync: 4',
        ],
    ] as const)('explains the outcome of "Sync now" (%#)', async (result, text) => {
        server.use(...createBackend().handlers);
        vi.mocked(runSync).mockResolvedValue(result);
        const { user } = renderApp('/settings');
        await user.click(await screen.findByRole('button', { name: 'Sync now' }));
        expect(
            await screen.findByText(
                (content, element) =>
                    element?.getAttribute('role') === 'status' && content.includes(text),
            ),
        ).toBeInTheDocument();
    });

    it('says when nothing was synced yet and storage details are unknown', async () => {
        server.use(...createBackend().handlers);
        vi.mocked(runSync).mockRejectedValue(new Error('boom'));
        const { user } = renderApp('/settings');
        expect(await screen.findByText('Not synced from this device yet.')).toBeInTheDocument();
        expect(await screen.findByText('Everything on this device is synced.')).toBeInTheDocument();
        expect(
            await screen.findByText('This browser does not report the storage usage.'),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Sync now' }));
        expect(await screen.findByText(/^Sync failed\./)).toBeInTheDocument();
    });
});
