/** Settings (§8.9): notification preferences per event and channel. */
import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { writeJson } from '../../src/lib/storage/local.ts';
import { createBackend, fail, ok } from '../utils/backend.ts';
import { renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();

beforeEach(() => {
    setOrganizationId(1);
});

async function preferences() {
    return screen.findByRole('region', { name: 'Notifications' });
}

describe('notification preferences', () => {
    it('shows a checkbox per event and channel with localized names', async () => {
        server.use(...createBackend().handlers);
        renderApp('/settings');
        const region = await preferences();
        const review = await within(region).findByRole('group', { name: 'Review assigned to me' });
        expect(within(review).getByRole('checkbox', { name: 'In the app' })).toBeChecked();
        expect(within(review).getByRole('checkbox', { name: 'Email' })).toBeChecked();
        const changes = within(region).getByRole('group', { name: 'Changes requested' });
        expect(within(changes).getByRole('checkbox', { name: 'In the app' })).toBeChecked();
        expect(within(changes).getByRole('checkbox', { name: 'Email' })).not.toBeChecked();
        expect(within(region).getByRole('group', { name: 'Submission approved' })).toBeVisible();
        expect(within(region).getByRole('group', { name: 'New form available' })).toBeVisible();
    });

    it('saves all events and announces success', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        const { user } = renderApp('/settings');
        const region = await preferences();
        const approved = await within(region).findByRole('group', { name: 'Submission approved' });
        await user.click(within(approved).getByRole('checkbox', { name: 'Email' }));
        const review = within(region).getByRole('group', { name: 'Review assigned to me' });
        await user.click(within(review).getByRole('checkbox', { name: 'In the app' }));
        await user.click(within(region).getByRole('button', { name: 'Save' }));
        const saved = await within(region).findByText('Notification settings saved.');
        expect(saved.parentElement).toHaveAttribute('role', 'status');
        expect(backend.state.preferenceSaves).toEqual([
            {
                events: [
                    { event: 'review_assigned', channels: { database: false, mail: true } },
                    { event: 'changes_requested', channels: { database: true, mail: false } },
                    { event: 'submission_approved', channels: { database: false, mail: true } },
                    { event: 'form_available', channels: { database: true, mail: false } },
                ],
            },
        ]);
        // A new change hides the old confirmation.
        await user.click(within(review).getByRole('checkbox', { name: 'Email' }));
        expect(within(region).queryByText('Notification settings saved.')).toBeNull();
    });

    it('reports save errors and offline', async () => {
        server.use(...createBackend().handlers);
        server.use(
            http.put(
                '*/api/v1/me/notification-preferences',
                () => fail(422, 'validation_failed', [{ pointer: '/events/0/event', code: 'in' }]),
                { once: true },
            ),
            http.put('*/api/v1/me/notification-preferences', () => HttpResponse.error()),
        );
        const { user } = renderApp('/settings');
        const region = await preferences();
        await within(region).findByRole('group', { name: 'Review assigned to me' });
        await user.click(within(region).getByRole('button', { name: 'Save' }));
        expect(await within(region).findByRole('alert')).toHaveTextContent(
            'The settings could not be saved. Please try again.',
        );
        await user.click(within(region).getByRole('button', { name: 'Save' }));
        await waitFor(() => {
            expect(within(region).getByRole('alert')).toHaveTextContent(
                'You are offline. Save again when you are online.',
            );
        });
    });

    it('names unknown events readably and explains an empty list', async () => {
        server.use(...createBackend().handlers);
        server.use(
            http.get('*/api/v1/me/notification-preferences', () =>
                ok({
                    events: [{ event: 'export_ready', channels: { database: true, mail: true } }],
                }),
            ),
        );
        const { unmount } = renderApp('/settings');
        const region = await preferences();
        expect(await within(region).findByRole('group', { name: 'export ready' })).toBeVisible();
        unmount();
        server.use(http.get('*/api/v1/me/notification-preferences', () => ok({ events: [] })));
        renderApp('/settings');
        expect(
            await within(await preferences()).findByText(
                'There are no notifications to configure.',
            ),
        ).toBeVisible();
    });

    it('offers a retry when loading fails', async () => {
        let calls = 0;
        server.use(...createBackend().handlers);
        server.use(
            http.get('*/api/v1/me/notification-preferences', () => {
                calls++;
                return calls === 1 ? fail(403, 'forbidden') : ok({ events: [] });
            }),
        );
        const { user } = renderApp('/settings');
        const region = await preferences();
        await user.click(await within(region).findByRole('button', { name: 'Try again' }));
        expect(
            await within(region).findByText('There are no notifications to configure.'),
        ).toBeVisible();
    });

    it('speaks German', async () => {
        writeJson('settings', { language: 'de', largeText: false });
        server.use(...createBackend().handlers);
        renderApp('/settings');
        const region = await screen.findByRole('region', { name: 'Benachrichtigungen' });
        const review = await within(region).findByRole('group', { name: 'Prüfung mir zugewiesen' });
        expect(within(review).getByRole('checkbox', { name: 'In der App' })).toBeChecked();
        expect(within(review).getByRole('checkbox', { name: 'E-Mail' })).toBeChecked();
        expect(within(region).getByRole('button', { name: 'Speichern' })).toBeVisible();
    });
});
