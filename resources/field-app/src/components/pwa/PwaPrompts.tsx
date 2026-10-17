/**
 * Install, update and offline-store prompts below the app header (ADR 0035). At most one banner
 * shows, by urgency: reload (offline store changed) > update > iOS data warning > install. The
 * install and iOS banners stay out of focus mode (filling a form).
 */
import type { ReactNode } from 'react';
import type { PlatformInfo } from '../../lib/pwa/install.ts';
import { InstallPrompt } from './InstallPrompt.tsx';
import { DatabaseBanner, UpdateBanner, useUpdateState } from './UpdateBanners.tsx';

export interface PwaPromptsProps {
    /** Tests: platform override; defaults to the browser's. */
    readonly platform?: PlatformInfo;
    /** Tests: reload override. */
    readonly reload?: () => void;
    /** Filling a form: only the reload and update banners may interrupt. */
    readonly focusMode?: boolean;
}

const reloadPage = (): void => {
    window.location.reload();
};

function Banner({ platform, reload = reloadPage, focusMode = false }: PwaPromptsProps): ReactNode {
    const { database, needRefresh } = useUpdateState();
    if (database !== null) {
        return <DatabaseBanner reload={reload} />;
    }
    if (needRefresh) {
        return <UpdateBanner />;
    }
    return focusMode ? null : <InstallPrompt {...(platform === undefined ? {} : { platform })} />;
}

export function PwaPrompts(props: PwaPromptsProps = {}): ReactNode {
    return (
        <div className="mx-auto flex max-w-3xl flex-col gap-2 px-4 pt-2 empty:hidden">
            <Banner {...props} />
        </div>
    );
}
