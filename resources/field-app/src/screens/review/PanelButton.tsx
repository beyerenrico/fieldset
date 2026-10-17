/**
 * A button in a sync panel or notice: the label wraps (long German texts on a 360 px screen) and a
 * busy button stays focusable (`aria-disabled`, like the update banner) and ignores clicks.
 */
import type { ReactNode } from 'react';
import { Button, type ButtonProps } from '../../components/ui/button.tsx';
import { cn } from '../../lib/cn.ts';

export const PANEL_BUTTON = 'h-auto whitespace-normal text-center';

export interface PanelButtonProps extends Omit<ButtonProps, 'onClick' | 'disabled'> {
    readonly busy?: boolean;
    readonly onClick: () => void;
}

export function PanelButton({
    busy = false,
    onClick,
    className,
    ...props
}: PanelButtonProps): ReactNode {
    return (
        <Button
            {...props}
            aria-disabled={busy ? true : undefined}
            className={cn(PANEL_BUTTON, className)}
            onClick={() => {
                if (!busy) {
                    onClick();
                }
            }}
        />
    );
}
