import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { InstallPrompt } from '../../../src/components/pwa/InstallPrompt.tsx';
import { db } from '../../../src/lib/db/db.ts';
import { setCurrentUserId } from '../../../src/lib/db/scope.ts';
import {
    chooseInstallOffer,
    INSTALL_DISMISSED_KEY,
    isIos,
    isStandalone,
    needsIosDataWarning,
    unsyncedCount,
} from '../../../src/lib/pwa/install.ts';
import {
    promptInstall,
    resetInstallPromptForTests,
    startInstallCapture,
} from '../../../src/lib/pwa/install-prompt.ts';
import { setOrganizationId } from '../../../src/lib/session/org-store.ts';
import { attachmentRow } from '../../utils/b-attachments.ts';
import {
    ANDROID,
    ANDROID_INSTALLED,
    installPromptEvent,
    IPAD,
    IPHONE,
    IPHONE_HOME_SCREEN,
    MAC,
    renderWithI18n,
} from '../../utils/b-pwa.tsx';
import { setupTestServer } from '../../utils/server.ts';

setupTestServer();

let stopCapture: () => void = () => undefined;

beforeEach(() => {
    setOrganizationId(1);
    setCurrentUserId(7);
    stopCapture = startInstallCapture();
});
afterEach(() => {
    stopCapture();
    resetInstallPromptForTests();
    setCurrentUserId(null);
});

describe('install decision', () => {
    it('detects iOS (incl. iPadOS with a desktop user agent) and standalone mode', () => {
        expect([IPHONE, IPAD, MAC, ANDROID].map(isIos)).toEqual([true, true, false, false]);
        expect([IPHONE, IPHONE_HOME_SCREEN, ANDROID_INSTALLED].map(isStandalone)).toEqual([
            false,
            true,
            true,
        ]);
    });

    it('offers the Chromium button, iOS instructions, or nothing', () => {
        const offer = (platform: typeof IPHONE, canPrompt = false, dismissed = false) =>
            chooseInstallOffer({ platform, canPrompt, dismissed });
        expect(offer(ANDROID, true)).toBe('button');
        expect(offer(ANDROID)).toBe('none');
        expect(offer(ANDROID_INSTALLED, true)).toBe('none');
        expect(offer(IPHONE)).toBe('ios');
        expect(offer(IPAD)).toBe('ios');
        expect(offer(IPHONE_HOME_SCREEN)).toBe('none');
        expect(offer(MAC)).toBe('none');
        expect(offer(IPHONE, false, true)).toBe('none');
        expect(offer(ANDROID, true, true)).toBe('none');
    });

    it('warns iOS users outside the home screen while unsynced work exists', () => {
        expect(unsyncedCount({ ops: 2, dirtyDrafts: 1, pendingAttachments: 3, blocked: 9 })).toBe(
            6,
        );
        expect(needsIosDataWarning(IPHONE, 1)).toBe(true);
        expect(needsIosDataWarning(IPHONE, 0)).toBe(false);
        expect(needsIosDataWarning(IPHONE_HOME_SCREEN, 5)).toBe(false);
        expect(needsIosDataWarning(ANDROID, 5)).toBe(false);
    });
});

describe('install prompt', () => {
    it('shows the Install button after beforeinstallprompt and opens the browser dialog', async () => {
        const user = userEvent.setup();
        renderWithI18n(<InstallPrompt platform={ANDROID} />);
        expect(screen.queryByRole('button', { name: 'Install app' })).not.toBeInTheDocument();

        const event = installPromptEvent();
        const prompt = vi.spyOn(event, 'prompt');
        act(() => {
            window.dispatchEvent(event);
        });
        expect(event.defaultPrevented).toBe(true);
        expect(screen.getByRole('status')).toHaveTextContent('Install the app to use it offline');
        await user.click(screen.getByRole('button', { name: 'Install app' }));
        expect(prompt).toHaveBeenCalledOnce();
        expect(screen.queryByRole('button', { name: 'Install app' })).not.toBeInTheDocument();
        await expect(promptInstall()).resolves.toBe('unavailable');
    });

    it('forgets the prompt once the app is installed', () => {
        renderWithI18n(<InstallPrompt platform={ANDROID} />);
        act(() => {
            window.dispatchEvent(installPromptEvent());
        });
        expect(screen.getByRole('button', { name: 'Install app' })).toBeInTheDocument();
        act(() => {
            window.dispatchEvent(new Event('appinstalled'));
        });
        expect(screen.queryByRole('button', { name: 'Install app' })).not.toBeInTheDocument();
    });

    it('remembers a dismissal on this device', async () => {
        const user = userEvent.setup();
        const { unmount } = renderWithI18n(<InstallPrompt platform={IPHONE} />);
        expect(await screen.findByText(/tap Share, then “Add to Home Screen”/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Not now' }));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(localStorage.getItem(`fsa.${INSTALL_DISMISSED_KEY}`)).toBe('true');
        unmount();
        renderWithI18n(<InstallPrompt platform={IPHONE} />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('warns about unsynced items on iOS, hideable for this session only', async () => {
        const user = userEvent.setup();
        await db.attachments.bulkPut([
            attachmentRow({ uuid: 'a1' }),
            attachmentRow({ uuid: 'a2', state: 'failed' }),
            attachmentRow({ uuid: 'other', userId: 99 }),
        ]);
        localStorage.setItem(`fsa.${INSTALL_DISMISSED_KEY}`, 'true');
        const { unmount } = renderWithI18n(<InstallPrompt platform={IPHONE} />);
        const warning = await screen.findByText(/2 items are not synced yet/);
        expect(warning.closest('[role="status"]')).toHaveTextContent('Add to Home Screen');
        await user.click(screen.getByRole('button', { name: 'Hide' }));
        expect(screen.queryByText(/not synced yet/)).not.toBeInTheDocument();
        unmount();

        const second = renderWithI18n(<InstallPrompt platform={IPHONE} />);
        await new Promise((resolve) => setTimeout(resolve, 30));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        second.unmount();

        sessionStorage.clear(); // a new browser session: the warning returns
        renderWithI18n(<InstallPrompt platform={IPHONE} />);
        expect(await screen.findByText(/2 items are not synced yet/)).toBeInTheDocument();
    });

    it('does not warn on the home screen or outside iOS', async () => {
        await db.attachments.put(attachmentRow());
        renderWithI18n(<InstallPrompt platform={IPHONE_HOME_SCREEN} />);
        renderWithI18n(<InstallPrompt platform={ANDROID} />);
        renderWithI18n(<InstallPrompt platform={MAC} />);
        await new Promise((resolve) => setTimeout(resolve, 30));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});
