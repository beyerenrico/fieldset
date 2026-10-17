/** Settings storage card (F3 gate fix 17): live usage, "almost full" warning, ask to keep the data. */
import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { setMeta } from '../../../src/lib/db/db.ts';
import { StorageCard } from '../../../src/screens/settings/StorageCard.tsx';
import { renderWithI18n } from '../../utils/b-pwa.tsx';
import { setVisibility } from '../../utils/visibility.ts';
import { setupTestServer } from '../../utils/server.ts';

setupTestServer();

const GB = 1_000_000_000;

/** navigator.storage with a changeable usage and a persist() answer. */
function stubStorage(options: { usage: number; persisted: boolean; grant: boolean }) {
    const state = { ...options };
    const persist = vi.fn(() => {
        state.persisted = state.grant;
        return Promise.resolve(state.grant);
    });
    Object.defineProperty(navigator, 'storage', {
        configurable: true,
        value: {
            persisted: () => Promise.resolve(state.persisted),
            persist,
            estimate: () => Promise.resolve({ usage: state.usage, quota: 2 * GB }),
        },
    });
    return { state, persist };
}

afterEach(() => {
    Reflect.deleteProperty(navigator, 'storage');
});

describe('storage card', () => {
    it('warns above 80 % and reads the usage again when the app becomes visible', async () => {
        const storage = stubStorage({ usage: 1.5 * GB, persisted: true, grant: true });
        renderWithI18n(<StorageCard />);
        expect(await screen.findByText('1.5 GB of 2 GB used')).toBeInTheDocument();
        expect(screen.queryByText(/Storage almost full/)).not.toBeInTheDocument();

        storage.state.usage = 1.7 * GB;
        setVisibility('hidden');
        setVisibility('visible');
        expect(await screen.findByText('1.7 GB of 2 GB used')).toBeInTheDocument();
        expect(screen.getByText('Storage almost full – sync now to free space.')).toHaveAttribute(
            'role',
            'status',
        );
    });

    it('reads the usage again after a sync run', async () => {
        const storage = stubStorage({ usage: 0.5 * GB, persisted: true, grant: true });
        renderWithI18n(<StorageCard />);
        expect(await screen.findByText('500 MB of 2 GB used')).toBeInTheDocument();
        storage.state.usage = 0.2 * GB;
        await act(() =>
            setMeta('sync', { state: 'idle', lastRunAt: 5, lastSuccessAt: 5, lastError: null }),
        );
        expect(await screen.findByText('200 MB of 2 GB used')).toBeInTheDocument();
    });

    it('asks the browser to keep the data when it is not persistent', async () => {
        const user = userEvent.setup();
        const storage = stubStorage({ usage: GB, persisted: false, grant: true });
        renderWithI18n(<StorageCard />);
        await user.click(await screen.findByRole('button', { name: 'Keep data on this device' }));
        expect(storage.persist).toHaveBeenCalledOnce();
        expect(
            await screen.findByText('The browser keeps this data (persistent storage).'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Keep data on this device' }),
        ).not.toBeInTheDocument();
    });

    it('says so when the browser declines', async () => {
        const user = userEvent.setup();
        stubStorage({ usage: GB, persisted: false, grant: false });
        renderWithI18n(<StorageCard />);
        await user.click(await screen.findByRole('button', { name: 'Keep data on this device' }));
        expect(await screen.findByText(/The browser did not allow it/)).toHaveAttribute(
            'role',
            'status',
        );
    });
});
