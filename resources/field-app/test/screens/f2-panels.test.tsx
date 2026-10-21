/**
 * Fill/review panels of a paused draft (UX/a11y review, ADR 0035): persistent regions that do not
 * steal focus, only the choices that can help, focus handling of Discard, confirmations after
 * Discard and Copy, and a refused submit that can be submitted again.
 */
import { screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { db } from '../../src/lib/db/db.ts';
import { setOrganizationId } from '../../src/lib/session/org-store.ts';
import * as queue from '../../src/lib/submit/queue.ts';
import { draftRow, setupSyncScope } from '../utils/a-store.ts';
import { createBackend } from '../utils/backend.ts';
import { writesOffline } from '../utils/f2-rows.ts';
import { preloadLazyScreens, renderApp } from '../utils/render-app.tsx';
import { server, setupTestServer } from '../utils/server.ts';

vi.mock('@fieldset/fieldset-react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@fieldset/fieldset-react')>();
    const { FakeRenderer } = await import('../utils/fake-renderer.tsx');
    return { ...actual, FormRenderer: FakeRenderer };
});

setupTestServer();
preloadLazyScreens();
setupSyncScope();

const UUID = '0192f1c4-0000-7000-8000-0000000000c1';

beforeEach(() => {
    setOrganizationId(1);
    server.use(...createBackend().handlers);
    writesOffline();
});
afterEach(() => {
    vi.restoreAllMocks();
});

async function blockedDraft(code: string) {
    await db.drafts.put(
        draftRow({ uuid: UUID, sync: 'blocked', blockedBy: code, data: { customer: 'ACME' } }),
    );
}

async function openPanel(path = `/fill/${UUID}`) {
    const app = renderApp(path);
    const panel = await screen.findByRole('region', { name: 'Needs your decision' });
    return { ...app, panel };
}

describe('the decision panel', () => {
    it('appears without taking focus, says what to do and offers only "Try again"', async () => {
        await blockedDraft('form_access_revoked');
        const { panel } = await openPanel();
        expect(panel).toHaveTextContent(
            'You no longer have access to this form. Your entries stay on this device – ask your office to restore access, then tap Try again.',
        );
        expect(panel).not.toContainElement(document.activeElement as HTMLElement);
        expect(screen.getAllByRole('status').map((s) => s.textContent)).toContain(
            'Needs your decision',
        );
        const buttons = within(panel).getAllByRole('button');
        expect(buttons.map((button) => button.textContent)).toEqual([
            'Try again',
            'Discard my changes',
        ]);
        expect(buttons[0]).toHaveClass('bg-primary', 'whitespace-normal');
        expect(buttons[1]).not.toHaveClass('bg-primary');
        expect(screen.getByRole('button', { name: 'Review' })).toBeDisabled();
    });

    it('offers "Save as a new draft" (no retry) when the submission is gone', async () => {
        await blockedDraft('not_found');
        const { panel } = await openPanel();
        expect(panel).toHaveTextContent('This submission no longer exists in the office.');
        expect(within(panel).getByRole('button', { name: 'Save as a new draft' })).toHaveClass(
            'bg-primary',
        );
        expect(within(panel).queryByRole('button', { name: 'Try again' })).toBeNull();
    });

    it('confirms "Save as a new draft" on the new draft', async () => {
        await blockedDraft('not_found');
        const { panel, user, history } = await openPanel();
        await user.click(within(panel).getByRole('button', { name: 'Save as a new draft' }));
        await waitFor(() => {
            expect(history.location.pathname).not.toBe(`/app/fill/${UUID}`);
        });
        const copied = await screen.findByText('Your entries were copied into a new draft.');
        expect(copied.closest('[role="status"]')).not.toBeNull();
        const copy = history.location.pathname.split('/').at(-1) ?? '';
        expect(await db.drafts.get(copy)).toMatchObject({ data: { customer: 'ACME' } });
    });

    it('moves focus into the Discard confirmation, back on Cancel, and confirms on Home', async () => {
        await blockedDraft('forbidden');
        const { panel, user, history } = await openPanel();
        await user.click(within(panel).getByRole('button', { name: 'Discard my changes' }));
        const group = within(panel).getByRole('group', {
            name: 'Really discard? This cannot be undone.',
        });
        expect(group).toHaveFocus();
        await user.click(within(group).getByRole('button', { name: 'Cancel' }));
        expect(within(panel).getByRole('button', { name: 'Discard my changes' })).toHaveFocus();

        await user.click(within(panel).getByRole('button', { name: 'Discard my changes' }));
        await user.click(within(panel).getByRole('button', { name: 'Discard' }));
        await waitFor(() => {
            expect(history.location.pathname).toBe('/app/');
        });
        const done = await screen.findByText('Your local changes were discarded.');
        expect(done.closest('[role="status"]')).not.toBeNull();
        expect(await db.drafts.get(UUID)).toBeUndefined();
    });

    it('shows the German copy with the formal address', async () => {
        localStorage.setItem('fieldset.settings', JSON.stringify({ language: 'de', largeText: false }));
        await blockedDraft('version_retired');
        renderApp(`/fill/${UUID}`);
        const panel = await screen.findByRole('region', { name: 'Entscheidung nötig' });
        expect(panel).toHaveTextContent(
            'Ihre Eingaben bleiben auf diesem Gerät – kopieren Sie sie in die aktuelle Version',
        );
        expect(
            await within(panel).findByRole('button', { name: 'In aktuelle Version kopieren' }),
        ).toBeInTheDocument();
    });
});

describe('a refused submit on the review screen', () => {
    async function refused() {
        await db.drafts.put(
            draftRow({
                uuid: UUID,
                sync: 'blocked',
                blockedBy: 'submit_refused',
                data: { customer: 'ACME' },
                submitErrors: [{ pointer: '/customer', code: 'required' }],
            }),
        );
    }

    it('explains it without a decision panel and submits again', async () => {
        await refused();
        const { user, history } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        expect(
            await screen.findByText(
                'Not submitted: 1 field needs fixing. Open the form, correct the marked field and submit again.',
                { selector: '[data-testid="refusal"] p' },
            ),
        ).toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Needs your decision' })).toBeNull();
        const submit = screen.getByRole('button', { name: 'Submit' });
        expect(submit).toBeEnabled();
        await user.click(submit);
        // Offline: queued again, the draft is no longer blocked.
        await waitFor(() => {
            expect(history.location.pathname).toBe(`/app/submissions/${UUID}`);
        });
        expect(await db.drafts.get(UUID)).toMatchObject({ sync: 'queued', blockedBy: null });
        expect((await db.outbox.toArray()).map((op) => op.kind)).toContain('submit');
    });

    it('leaves the busy state and names a full storage when queueing throws', async () => {
        await refused();
        vi.spyOn(queue, 'enqueueAndRun').mockRejectedValue(
            new Error('QuotaExceeded', { cause: { code: 'storage_full' } }),
        );
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const { user } = renderApp(`/fill/${UUID}/review`);
        await screen.findByRole('heading', { name: 'Review and sign' });
        await user.click(screen.getByRole('button', { name: 'Submit' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            "Not saved – this device's storage is full. Keep this page open, then sync or free up space.",
        );
        expect(screen.getByRole('button', { name: 'Submit' })).toBeEnabled();
    });
});
