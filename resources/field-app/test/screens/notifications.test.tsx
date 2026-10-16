/** Notification centre (§8.9): header bell with unread badge, list, mark read, open targets. */
import { screen, waitFor, within } from '@testing-library/react';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { makeNotification, makeSubmission } from '../utils/fixtures.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

beforeEach(() => {
    setOrganizationId(1);
});

const ADMIN_URL = 'http://localhost:3000/admin/1/submissions/5';

function setup() {
    const backend = createBackend({
        notifications: [
            makeNotification(),
            makeNotification({
                id: 'n2',
                event: 'workflow_failed',
                title: 'Workflow failed',
                body: null,
                url: ADMIN_URL,
            }),
            makeNotification({
                id: 'n3',
                event: 'form_available',
                title: 'New form: Inspection',
                url: null,
                read_at: '2026-10-15T08:00:00+02:00',
            }),
            makeNotification({
                id: 'n4',
                event: 'workflow_notice',
                title: 'Please call the office',
                body: 'Re: SR-2026-00012',
                url: null,
            }),
        ],
    });
    backend.state.submissions.set(
        '0192f1c4-0000-7000-8000-0000000000a1',
        makeSubmission({ uuid: '0192f1c4-0000-7000-8000-0000000000a1', status: 'submitted' }),
    );
    server.use(...backend.handlers);
    return backend;
}

const bell = (count: number) =>
    screen.findByRole('link', {
        name: count === 0 ? 'Notifications' : `Notifications, ${String(count)} unread`,
    });

describe('notification bell', () => {
    it('shows the unread count in its name and badge and opens the list', async () => {
        setup();
        const { user, history } = renderApp('/');
        const link = await bell(3);
        expect(link).toHaveTextContent('3');
        expect(link.querySelector('[aria-hidden="true"]:not(svg)')).toHaveTextContent('3');
        // Announced politely once loaded (not by colour or the badge alone).
        const announcement = screen.getByText('Notifications, 3 unread');
        expect(announcement).toHaveAttribute('role', 'status');
        await user.click(link);
        expect(
            await screen.findByRole('heading', { level: 1, name: 'Notifications' }),
        ).toBeVisible();
        expect(history.location.pathname).toBe('/app/notifications');
        await waitFor(() => {
            expect(document.title).toBe('Notifications – Field App');
        });
        expect(link).toHaveAttribute('aria-current', 'page');
    });

    it('has a plain name without unread items', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        renderApp('/');
        const link = await bell(0);
        await waitFor(() => {
            expect(backend.state.notificationCalls).toBe(1);
        });
        expect(link).not.toHaveTextContent(/\d/);
    });

    it('caps a large count at 99+', async () => {
        server.use(...createBackend().handlers);
        server.use(
            http.get('*/api/v1/notifications', () =>
                ok([], {}, { current_page: 1, last_page: 1, total: 120, unread: 120 }),
            ),
        );
        renderApp('/');
        expect(await bell(120)).toHaveTextContent('99+');
    });

    it('speaks German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        setup();
        renderApp('/');
        expect(
            await screen.findByRole('link', { name: 'Benachrichtigungen, 3 ungelesen' }),
        ).toBeInTheDocument();
    });
});

describe('notifications screen', () => {
    it('lists title, body, time and unread state', async () => {
        setup();
        renderApp('/notifications');
        const list = await screen.findByRole('list', { name: 'Notifications' });
        const items = within(list).getAllByRole('listitem');
        expect(items).toHaveLength(4);
        expect(items[0]).toHaveTextContent('Review requested');
        expect(items[0]).toHaveTextContent('waiting for your review');
        expect(items[0]).toHaveTextContent(/Oct 16, 2026/);
        expect(items[0]).toHaveTextContent('New');
        expect(within(items[2]!).queryByText('New')).not.toBeInTheDocument();
        expect(within(items[3]!).getByText('New')).toBeInTheDocument();
        expect(screen.getByText('3 unread')).toBeInTheDocument();
    });

    it('marks all as read, announces it and updates the bell', async () => {
        const backend = setup();
        const { user } = renderApp('/notifications');
        const markAll = await screen.findByRole('button', { name: 'Mark all as read' });
        await user.click(markAll);
        expect(
            await screen.findByText('All notifications are marked as read.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('All notifications are marked as read.').parentElement,
        ).toHaveAttribute('role', 'status');
        expect(backend.state.reads).toEqual([{}]);
        expect(await bell(0)).toBeInTheDocument();
        expect(screen.queryByText('New')).not.toBeInTheDocument();
        expect(screen.getByText('0 unread')).toBeInTheDocument();
        // Stays focusable (aria-disabled), and pressing it again does nothing.
        expect(markAll).toHaveFocus();
        expect(markAll).toHaveAttribute('aria-disabled', 'true');
        await user.click(markAll);
        expect(backend.state.reads).toHaveLength(1);
    });

    it('marks one item as read and moves focus to its title', async () => {
        const backend = setup();
        const { user } = renderApp('/notifications');
        const heading = await screen.findByRole('heading', { name: 'Please call the office' });
        const button = screen
            .getAllByRole('button', { name: 'Mark as read' })
            .find((candidate) => candidate.getAttribute('aria-describedby') === heading.id);
        expect(button).toHaveAccessibleDescription('Please call the office');
        await user.click(button!);
        await waitFor(() => {
            expect(heading).toHaveFocus();
        });
        expect(backend.state.reads).toEqual([{ ids: ['n4'] }]);
        expect(await bell(2)).toBeInTheDocument();
    });

    it('opens a Field App link in the app and marks it read', async () => {
        const backend = setup();
        const { user, history } = renderApp('/notifications');
        const link = await screen.findByRole('link', { name: 'Review requested' });
        expect(link).toHaveAttribute(
            'href',
            '/app/submissions/0192f1c4-0000-7000-8000-0000000000a1',
        );
        expect(link).not.toHaveAttribute('target');
        await user.click(link);
        await waitFor(() => {
            expect(history.location.pathname).toBe(
                '/app/submissions/0192f1c4-0000-7000-8000-0000000000a1',
            );
        });
        await waitFor(() => {
            expect(backend.state.reads).toEqual([{ ids: ['n1'] }]);
        });
    });

    it('opens other links in a new tab and marks them read', async () => {
        const backend = setup();
        const { user, history } = renderApp('/notifications');
        const link = await screen.findByRole('link', { name: /Workflow failed/ });
        expect(link).toHaveAttribute('href', ADMIN_URL);
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAccessibleName('Workflow failed (opens in a new tab)');
        // jsdom cannot open tabs: stop the default action after the app handled the click.
        const stop = (event: Event) => {
            event.preventDefault();
        };
        document.addEventListener('click', stop);
        await user.click(link);
        document.removeEventListener('click', stop);
        await waitFor(() => {
            expect(backend.state.reads).toEqual([{ ids: ['n2'] }]);
        });
        expect(history.location.pathname).toBe('/app/notifications');
    });

    it('shows an error when marking fails and offers to load more pages', async () => {
        setup();
        server.use(
            http.post('*/api/v1/notifications/read', () => fail(500, 'server_error')),
            http.get('*/api/v1/notifications', ({ request }) => {
                const page = Number(new URL(request.url).searchParams.get('page') ?? '1');
                return ok(
                    [makeNotification({ id: `p${String(page)}`, title: `Page ${String(page)}` })],
                    {},
                    { current_page: page, last_page: 2, total: 2, unread: 2 },
                );
            }),
        );
        const { user } = renderApp('/notifications');
        await user.click(await screen.findByRole('button', { name: 'Mark all as read' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Could not mark as read. Please try again.',
        );
        await user.click(screen.getByRole('button', { name: 'Load more' }));
        expect(await screen.findByText('Page 2')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument();
    });

    it('explains an empty list and a failed load', async () => {
        server.use(...createBackend().handlers);
        const { unmount } = renderApp('/notifications');
        expect(await screen.findByText('No notifications yet.')).toBeInTheDocument();
        unmount();
        server.use(http.get('*/api/v1/notifications', () => fail(403, 'forbidden')));
        renderApp('/notifications');
        expect(await screen.findByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });
});
