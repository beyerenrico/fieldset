/** Install, update and offline-store prompts below the app header (ADR 0035). */
import type { ReactNode } from 'react';
import type { PlatformInfo } from '../../lib/pwa/install.ts';
import { InstallPrompt } from './InstallPrompt.tsx';
import { DatabaseBanner, UpdateBanner } from './UpdateBanners.tsx';

export interface PwaPromptsProps {
    /** Tests: platform override; defaults to the browser's. */
    readonly platform?: PlatformInfo;
    /** Tests: reload override. */
    readonly reload?: () => void;
}

const reloadPage = (): void => {
    window.location.reload();
};

export function PwaPrompts({ platform, reload = reloadPage }: PwaPromptsProps = {}): ReactNode {
    return (
        <div className="mx-auto flex max-w-3xl flex-col gap-2 px-4 pt-2 empty:hidden">
            <DatabaseBanner reload={reload} />
            <UpdateBanner />
            <InstallPrompt {...(platform === undefined ? {} : { platform })} />
        </div>
    );
}
