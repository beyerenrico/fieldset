import { screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../../src/lib/db/db.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { createBackend } from '../../utils/backend.ts';
import { FORMS, makeVersion } from '../../utils/fixtures.ts';
import { renderApp } from '../../utils/render-app.tsx';
import { server, setupTestServer } from '../../utils/server.ts';

setupTestServer();

beforeEach(() => {
    setOrganizationId(1);
    server.use(...createBackend().handlers);
});
afterEach(() => {
    vi.restoreAllMocks();
});

function goOffline(): void {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
}

describe('catalog screen offline', () => {
    it('explains that the forms are not available offline when nothing is cached', async () => {
        goOffline();
        renderApp('/forms');
        const notice = await screen.findByText(/The forms are not available offline yet/);
        expect(notice.closest('[role="status"]')).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Start / })).not.toBeInTheDocument();
    });

    it('lists the cached forms and marks those whose version is not on the device', async () => {
        const [report, inspection] = FORMS;
        await db.forms.bulkPut([
            { ...report!, orgId: 1, cachedAt: 1 },
            { ...inspection!, orgId: 1, cachedAt: 1 },
        ]);
        await db.formVersions.put({ ...makeVersion(7, 3), orgId: 1, cachedAt: 1 });
        goOffline();
        const { user, history } = renderApp('/forms');

        const start = await screen.findByRole('button', { name: 'Start Service report' });
        expect(start).not.toHaveAttribute('aria-disabled');
        const unavailable = await screen.findByRole('button', { name: 'Start Inspection' });
        await vi.waitFor(() => {
            expect(unavailable).toHaveAttribute('aria-disabled', 'true');
        });
        // The note is visible outside the dimmed button and is its description.
        expect(unavailable).toHaveAccessibleDescription('Not available offline');
        expect(unavailable).not.toContainElement(screen.getByText('Not available offline'));
        expect(
            screen.getByText('You are offline. These are the forms saved on this device.'),
        ).toBeInTheDocument();

        await user.click(unavailable);
        expect(history.location.pathname).toBe('/app/forms');
    });
});
