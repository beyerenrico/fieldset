import { Wifi, WifiOff } from 'lucide-react';
import type { ReactNode } from 'react';
import { useOnline } from '../hooks/useOnline.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import { cn } from '../lib/cn.ts';

export function OnlineIndicator(): ReactNode {
    const t = useT();
    const online = useOnline();
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
            {online ? t('common.online') : t('common.offline')}
        </span>
    );
}
