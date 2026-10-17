/**
 * Screens on top of the offline store (ADR 0035): a form version that is not available offline,
 * and a local draft on a retired version (blocked before it syncs, copy to the current version).
 */
import { screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { enqueueDraftSave } from '../../src/lib/outbox/outbox.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import { SCOPE, draftRow, setupSyncScope } from '../utils/a-store.ts';
import { createBackend } from '../utils/backend.ts';
import { makeSubmission, makeVersion } from '../utils/fixtures.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@form-schema-app/form-schema-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@form-schema-app/form-schema-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000e7';
const NOT_CACHED =
    'This form version is not available offline yet. Connect to the internet once to download it.';

beforeEach(() => {
    setOrganizationId(1);
});

/** Form versions cannot be downloaded (no signal) until `restore()`; nothing is cached. */
function versionsUnreachable() {
    let reachable = false;
    server.use(
        http.get('*/api/v1/forms/:form/versions/:version', () =>
            reachable ? undefined : HttpResponse.error(),
        ),
    );
    return {
        restore: () => {
            reachable = true;
        },
    };
}

describe('form version not available offline', () => {
    it('explains it on the fill screen and loads the form on retry', async () => {
        const backend = createBackend();
        backend.state.submissions.set(UUID, makeSubmission({ uuid: UUID }));
        server.use(...backend.handlers);
        const versions = versionsUnreachable();
        const { user } = renderApp(`/fill/${UUID}`);

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent(NOT_CACHED);
        expect(alert).not.toHaveTextContent('Something went wrong');

        versions.restore();
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('heading', { name: 'Service report' })).toBeInTheDocument();
        expect(await db.formVersions.get(7)).toMatchObject({ orgId: 1 }); // cached for next time
    });

    it('explains it on the submission detail and shows the form on retry', async () => {
        const backend = createBackend();
        backend.state.submissions.set(
            UUID,
            makeSubmission({ uuid: UUID, status: 'submitted', number: 'SR-1', can_edit: false }),
        );
        server.use(...backend.handlers);
        const versions = versionsUnreachable();
        const { user } = renderApp(`/submissions/${UUID}`);

        expect(await screen.findByRole('alert')).toHaveTextContent(NOT_CACHED);
        expect(screen.queryByTestId('renderer')).not.toBeInTheDocument();

        versions.restore();
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByTestId('renderer')).toBeInTheDocument();
        expect(screen.queryByText(NOT_CACHED)).not.toBeInTheDocument();
    });
});

describe('local draft on a retired version', () => {
    it('offers "copy to the current version" before anything is sent', async () => {
        const backend = createBackend(); // form 3 is at version 7 now
        server.use(...backend.handlers);
        await db.formVersions.put({ ...makeVersion(6, 3), retired: true, orgId: 1, cachedAt: 1 });
        await db.drafts.put(
            draftRow({ uuid: UUID, formVersionId: 6, data: { customer: 'Offline entry' } }),
        );
        await enqueueDraftSave(SCOPE, UUID);
        const { user, history } = renderApp(`/fill/${UUID}`);

        expect(await screen.findByText(/This form version was retired/)).toBeInTheDocument();
        expect(backend.state.puts).toEqual([]);
        await user.click(
            await screen.findByRole('button', { name: 'Copy to the current version' }),
        );

        await vi.waitFor(() => {
            expect(history.location.pathname).not.toBe(`/app/fill/${UUID}`);
        });
        const copy = history.location.pathname.split('/').at(-1) ?? '';
        expect(await db.drafts.get(copy)).toMatchObject({
            formVersionId: 7,
            data: { customer: 'Offline entry' },
        });
        expect(await db.drafts.get(UUID)).toBeUndefined();
        expect(backend.state.puts.map((put) => put.uuid)).not.toContain(UUID);
    });
});

describe('new draft while offline', () => {
    it('opens a form from the catalog cache without a connection (M6 E2E finding)', async () => {
        const backend = createBackend();
        server.use(...backend.handlers);
        await db.formVersions.put({ ...makeVersion(7, 3), orgId: 1, cachedAt: 1 });
        // The connection dropped after the app started: the draft lookup and the version fail.
        server.use(
            http.get('*/api/v1/submissions/:uuid', () => HttpResponse.error()),
            http.get('*/api/v1/forms/:form/versions/:version', () => HttpResponse.error()),
        );
        renderApp(`/fill/${UUID}?formId=3&versionId=7`);

        expect(await screen.findByRole('heading', { name: 'Service report' })).toBeInTheDocument();
        expect(screen.queryByText(/No connection/)).not.toBeInTheDocument();
    });
});
