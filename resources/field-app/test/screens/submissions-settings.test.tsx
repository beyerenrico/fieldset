import { screen, within } from '@testing-library/react';
import { http } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { getDeviceRecord, saveDeviceRecord } from '../../src/lib/device/credentials.ts';
import { getOrganizationId, setOrganizationId } from '../../src/lib/session/org-store.ts';
import { kvGet, kvSet } from '../../src/lib/storage/kv.ts';
import { createBackend, ok } from '../utils/backend.ts';
import { ORG_A, ORG_B, makeMe, makeSubmission } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();

beforeEach(() => {
    setOrganizationId(1);
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
        await saveDeviceRecord({ deviceUuid: 'dev', secret: 'sec' });
        await kvSet('outbox', [1]);
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
        expect(await kvGet('outbox')).toBeUndefined();
        expect(await getDeviceRecord()).toEqual({ deviceUuid: 'dev', secret: null });
    });
});
