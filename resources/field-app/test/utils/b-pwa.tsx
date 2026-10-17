/** Platforms and render helpers for the PWA prompt tests (agent B). */
import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { I18nProvider } from '../../src/i18n/I18nProvider.tsx';
import type { PlatformInfo } from '../../src/lib/pwa/install.ts';

const SAFARI_MAC_UA =
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

export const IPHONE: PlatformInfo = {
    userAgent:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
    platform: 'iPhone',
    maxTouchPoints: 5,
    navigatorStandalone: false,
    displayStandalone: false,
};
export const IPHONE_HOME_SCREEN: PlatformInfo = { ...IPHONE, navigatorStandalone: true };
/** iPadOS requests desktop sites: a Mac user agent with touch points. */
export const IPAD: PlatformInfo = { ...IPHONE, userAgent: SAFARI_MAC_UA, platform: 'MacIntel' };
export const MAC: PlatformInfo = {
    userAgent: SAFARI_MAC_UA,
    platform: 'MacIntel',
    maxTouchPoints: 0,
    navigatorStandalone: false,
    displayStandalone: false,
};
export const ANDROID: PlatformInfo = {
    userAgent:
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
    platform: 'Linux armv8l',
    maxTouchPoints: 5,
    navigatorStandalone: undefined,
    displayStandalone: false,
};
export const ANDROID_INSTALLED: PlatformInfo = { ...ANDROID, displayStandalone: true };

export function renderWithI18n(element: ReactElement) {
    return render(<I18nProvider>{element}</I18nProvider>);
}

/** A Chromium `beforeinstallprompt` event whose prompt resolves with `outcome`. */
export function installPromptEvent(outcome: 'accepted' | 'dismissed' = 'accepted') {
    const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
        prompt: () => Promise.resolve(),
        userChoice: Promise.resolve({ outcome }),
    });
    return event;
}
