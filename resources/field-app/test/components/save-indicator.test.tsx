import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SaveIndicator } from '../../src/components/SaveIndicator.tsx';
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
});
