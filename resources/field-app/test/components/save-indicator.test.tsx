import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SaveIndicator } from '../../src/components/SaveIndicator.tsx';
import { I18nProvider } from '../../src/i18n/I18nProvider.tsx';
import type { SaveState } from '../../src/lib/autosave/types.ts';

function renderState(state: SaveState) {
    return render(
        <I18nProvider>
            <SaveIndicator state={state} />
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
