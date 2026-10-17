/**
 * One banner at a time below the header (F3 gate fix 13): reload > update > iOS warning > install,
 * outline buttons, no install or iOS banner while filling a form.
 */
import { act, screen } from '@testing-library/react';
import type { RegisterSWOptions } from 'vite-plugin-pwa/types';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PwaPrompts } from '../../../src/components/pwa/PwaPrompts.tsx';
import { db } from '../../../src/lib/db/db.ts';
import { setCurrentUserId } from '../../../src/lib/db/scope.ts';
import {
    resetUpdateStateForTests,
    showDatabaseNotice,
    startServiceWorker,
} from '../../../src/lib/pwa/sw-update.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { attachmentRow } from '../../utils/b-attachments.ts';
import { IPHONE, renderWithI18n } from '../../utils/b-pwa.tsx';
import { setOnline } from '../../utils/c-rows.ts';
import { setupTestServer } from '../../utils/server.ts';

setupTestServer();

let options: RegisterSWOptions = {};

beforeEach(async () => {
    setOrganizationId(1);
    setCurrentUserId(7);
    setOnline(false); // the iOS warning shows at once offline
    await db.attachments.put(attachmentRow());
    startServiceWorker((given) => {
        options = given;
        return () => Promise.resolve();
    }, vi.fn());
});

afterEach(() => {
    resetUpdateStateForTests();
    setCurrentUserId(null);
    vi.restoreAllMocks();
});

const banners = (): HTMLElement[] => [
    ...screen.queryAllByRole('status'),
    ...screen.queryAllByRole('alert'),
];

describe('PWA banners', () => {
    it('shows only the most urgent banner, each with outline buttons', async () => {
        renderWithI18n(<PwaPrompts platform={IPHONE} />);
        expect(await screen.findByText(/Submissions not synced yet: 1\./)).toBeInTheDocument();
        expect(banners()).toHaveLength(1);
        expect(screen.getByRole('button', { name: 'Hide' })).toHaveClass('border-2');

        act(() => {
            options.onNeedRefresh?.();
        });
        expect(await screen.findByText('A new version of the app is available.')).toBeVisible();
        expect(screen.queryByText(/not synced yet/)).not.toBeInTheDocument();
        expect(banners()).toHaveLength(1);
        expect(screen.getByRole('button', { name: 'Update now' })).toHaveClass('border-2');

        act(() => {
            showDatabaseNotice('outdated');
        });
        expect(await screen.findByRole('alert')).toHaveTextContent('updated in another tab');
        expect(banners()).toHaveLength(1);
        expect(screen.getByRole('button', { name: 'Reload' })).toHaveClass('border-2');
    });

    it('keeps the install and iOS banners out of focus mode, but not an update', async () => {
        renderWithI18n(<PwaPrompts platform={IPHONE} focusMode />);
        await new Promise((resolve) => setTimeout(resolve, 30));
        expect(banners()).toHaveLength(0);
        act(() => {
            options.onNeedRefresh?.();
        });
        expect(await screen.findByText('A new version of the app is available.')).toBeVisible();
    });
});
