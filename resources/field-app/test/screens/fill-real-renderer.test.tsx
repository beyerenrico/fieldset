/** Integration with the REAL renderer from packages/form-schema-react (no mock). */
import { screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { FAKE_TIMERS, setupSyncScope } from '../utils/a-store.ts';
import { createBackend } from '../utils/backend.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

setupTestServer();
preloadLazyScreens();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000cc';

beforeEach(() => {
    vi.useFakeTimers(FAKE_TIMERS);
    setOrganizationId(1);
});
afterEach(() => {
    vi.useRealTimers();
});

describe('fill screen with the real renderer', () => {
    it('edits a field, saves it on this device, syncs the draft and shows calculated values live', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        const { user } = renderApp(`/fill/${UUID}?formId=3&versionId=7`);
        const customer = await screen.findByLabelText(/Customer/);
        await user.type(customer, 'ACME');
        await vi.advanceTimersByTimeAsync(400);
        await waitFor(async () => {
            expect((await db.drafts.get(UUID))?.data).toMatchObject({ customer: 'ACME' });
        });
        await vi.advanceTimersByTimeAsync(1600);
        await waitFor(() => {
            expect(backend.state.puts.at(-1)?.body).toMatchObject({
                form_version_id: 7,
                data: { customer: 'ACME' },
            });
        });
        expect(backend.state.puts[0]?.body).not.toHaveProperty('revision');
        await user.click(screen.getByRole('button', { name: /Add entry|Eintrag hinzufügen/ }));
        await waitFor(() => {
            expect(screen.getByRole('status', { name: 'Count' })).toHaveTextContent('1.000000');
        });
        expect(screen.getByRole('button', { name: 'Review' })).toBeInTheDocument();
    });
});
