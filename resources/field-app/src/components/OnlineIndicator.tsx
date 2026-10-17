/**
 * Header connection state. The browser's online flag alone misses "lie-fi" (a network that reaches
 * nothing): when the last sync could not reach the server, it says "No connection" instead.
 */
import { Wifi, WifiOff } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import { cn } from '../lib/cn.ts';
import { useConnection } from './sync/useSyncStatus.ts';

export function OnlineIndicator(): ReactNode {
    const t = useT();
    const connection = useConnection();
    const online = connection === 'online';
    return (
        <span
            role="status"
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-1 text-sm font-semibold',
                online ? 'text-success' : 'bg-warning text-warning-foreground',
            )}
        >
            {online ? (
                <Wifi aria-hidden="true" className="size-4" />
            ) : (
                <WifiOff aria-hidden="true" className="size-4" />
            )}
            {online
                ? t('common.online')
                : t(connection === 'offline' ? 'common.offline' : 'shell.noConnection')}
        </span>
    );
}
