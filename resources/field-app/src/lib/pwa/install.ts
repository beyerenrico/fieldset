/**
 * Install prompts (ADR 0035, §9.2). Chromium offers `beforeinstallprompt` → an Install button. iOS
 * has no prompt: outside the home screen it gets instructions, and while unsynced work exists a
 * warning, because WebKit deletes script-writable storage after 7 days without use unless the web
 * app was added to the home screen.
 */
import type { UnsyncedSummary } from '../outbox/outbox.ts';
import { readJson, writeJson } from '../storage/local.ts';

export interface PlatformInfo {
    readonly userAgent: string;
    readonly platform: string;
    readonly maxTouchPoints: number;
    /** iOS Safari's `navigator.standalone` (undefined elsewhere). */
    readonly navigatorStandalone: boolean | undefined;
    /** `(display-mode: standalone)` matches. */
    readonly displayStandalone: boolean;
}

export type InstallOffer = 'none' | 'button' | 'ios';

/** Per device, kept in localStorage (`fsa.` prefix). */
export const INSTALL_DISMISSED_KEY = 'pwa.installDismissed';
/** Per browser session: the data warning returns while unsynced work remains. */
export const IOS_WARNING_HIDDEN_KEY = 'pwa.iosWarningHidden';

export function readPlatform(): PlatformInfo {
    const nav = navigator as Navigator & { readonly standalone?: boolean };
    return {
        userAgent: nav.userAgent,
        platform: nav.platform,
        maxTouchPoints: nav.maxTouchPoints,
        navigatorStandalone: nav.standalone,
        displayStandalone:
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(display-mode: standalone)').matches,
    };
}

/** iPhone/iPod/iPad, including iPadOS that reports itself as a Mac with touch. */
export function isIos(info: PlatformInfo): boolean {
    return (
        /iPad|iPhone|iPod/.test(info.userAgent) ||
        (info.platform === 'MacIntel' && info.maxTouchPoints > 1)
    );
}

export function isStandalone(info: PlatformInfo): boolean {
    return info.navigatorStandalone === true || info.displayStandalone;
}

export function chooseInstallOffer(options: {
    readonly platform: PlatformInfo;
    readonly canPrompt: boolean;
    readonly dismissed: boolean;
}): InstallOffer {
    const { platform, canPrompt, dismissed } = options;
    if (dismissed || isStandalone(platform)) {
        return 'none';
    }
    if (canPrompt) {
        return 'button';
    }
    return isIos(platform) ? 'ios' : 'none';
}

/** Items that would be lost with the browser's storage (blocked ones are counted in ops/drafts). */
export function unsyncedCount(summary: UnsyncedSummary): number {
    return summary.ops + summary.dirtyDrafts + summary.pendingAttachments;
}

export function needsIosDataWarning(platform: PlatformInfo, unsynced: number): boolean {
    return unsynced > 0 && isIos(platform) && !isStandalone(platform);
}

export function isInstallDismissed(): boolean {
    return readJson<unknown>(INSTALL_DISMISSED_KEY, false) === true;
}

export function dismissInstall(): void {
    writeJson(INSTALL_DISMISSED_KEY, true);
}

export function isIosWarningHidden(): boolean {
    return readJson<unknown>(IOS_WARNING_HIDDEN_KEY, false, 'session') === true;
}

export function hideIosWarning(): void {
    writeJson(IOS_WARNING_HIDDEN_KEY, true, 'session');
}
