import { useEffect, useState, useSyncExternalStore } from 'react';
import { reportAuthError } from '../session/auth-events.ts';
import { apiTransport } from './api-transport.ts';
import { AutosaveController } from './controller.ts';
import type { AutosaveOptions, AutosaveSnapshot } from './types.ts';

export type UseAutosaveOptions = Omit<AutosaveOptions, 'transport' | 'onAuthLost'> &
    Partial<Pick<AutosaveOptions, 'transport'>>;

export interface UseAutosave {
    readonly controller: AutosaveController;
    readonly snapshot: AutosaveSnapshot;
}

/** One controller per mounted form (remount with a `key` to switch submissions). */
export function useAutosave(options: UseAutosaveOptions): UseAutosave {
    const [controller] = useState(
        () =>
            new AutosaveController({
                ...options,
                transport: options.transport ?? apiTransport,
                onAuthLost: reportAuthError,
            }),
    );
    useEffect(() => {
        controller.start();
        return () => {
            controller.stop();
        };
    }, [controller]);
    const snapshot = useSyncExternalStore(controller.subscribe, controller.getSnapshot);
    return { controller, snapshot };
}
