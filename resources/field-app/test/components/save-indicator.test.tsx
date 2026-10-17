import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    isStorageFull,
    SaveIndicator,
    type SaveIndicatorProps,
} from '../../src/components/SaveIndicator.tsx';
import { I18nProvider } from '../../src/i18n/I18nProvider.tsx';
import type { SaveState } from '../../src/lib/autosave/types.ts';
import type { DraftSync } from '../../src/lib/db/schema.ts';

function renderState(state: SaveState, sync: DraftSync | null = null) {
    return render(
        <I18nProvider>
            <SaveIndicator state={state} sync={sync} />
        </I18nProvider>,
    );
}

describe('SaveIndicator', () => {
    it.each<SaveState>(['idle', 'pending', 'saving', 'saved'])(
        'shows %s without announcing it',
        (state) => {
            const { container } = renderState(state);
            expect(container.querySelector(`[data-state="${state}"]`)?.textContent).not.toBe('');
            expect(screen.getByRole('status')).toHaveTextContent(/^$/);
        },
    );

    it('says "saved on this device" and shows the sync state next to it (ADR 0035)', () => {
        const { container, rerender } = renderState('saved', 'queued');
        const indicator = container.querySelector('[data-state="saved"]');
        expect(indicator).toHaveTextContent('Saved on this device');
        expect(screen.getByTestId('sync-state')).toHaveTextContent('Waiting to sync');
        rerender(
            <I18nProvider>
                <SaveIndicator state="saved" sync="synced" />
            </I18nProvider>,
        );
        expect(screen.getByTestId('sync-state')).toHaveTextContent('Synced');
    });

    it('leaves the sync state out for problems (their panels explain them)', () => {
        renderState('conflict', 'conflict');
        expect(screen.queryByTestId('sync-state')).not.toBeInTheDocument();
    });

    it.each<[SaveState, RegExp]>([
        ['offline', /Offline/],
        ['error', /Could not save/],
        ['conflict', /Conflict/],
        ['locked', /no longer be edited/],
    ])('announces %s', (state, text) => {
        renderState(state);
        expect(screen.getByRole('status')).toHaveTextContent(text);
    });

    const STORAGE_FULL =
        "Not saved – this device's storage is full. Keep this page open, then sync or free up space.";

    it('names a full storage instead of a generic error (also wrapped as a cause)', () => {
        const full = new Error('sync run failed', { cause: { code: 'storage_full' } });
        const { container } = render(
            <I18nProvider>
                <SaveIndicator state="error" error={full} />
            </I18nProvider>,
        );
        expect(container.querySelector('[data-state="error"]')).toHaveTextContent(STORAGE_FULL);
        expect(screen.getByRole('status')).toHaveTextContent(STORAGE_FULL);
        expect(isStorageFull({ code: 'storage_full' })).toBe(true);
        expect(isStorageFull(new Error('QuotaExceededError'))).toBe(false);
    });

    it('announces a failed write once while it is retried (error → saving → error)', () => {
        const error = { code: 'storage_full' };
        const view = (props: SaveIndicatorProps) => (
            <I18nProvider>
                <SaveIndicator {...props} />
            </I18nProvider>
        );
        const { rerender } = render(view({ state: 'error', error }));
        const region = screen.getByRole('status');
        const node = region.firstChild;
        rerender(view({ state: 'saving', error }));
        expect(region).toHaveTextContent(STORAGE_FULL); // not cleared while retrying
        expect(region.firstChild).toBe(node);
        rerender(view({ state: 'error', error }));
        expect(region.firstChild).toBe(node);
        rerender(view({ state: 'saved', error: null }));
        expect(region).toHaveTextContent(/^$/);
    });

    it('says when another tab edits the draft', () => {
        render(
            <I18nProvider>
                <SaveIndicator state="locked" lockedElsewhere />
            </I18nProvider>,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Open in another tab – read-only here',
        );
    });
});
