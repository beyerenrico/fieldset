// Adapted from shadcn/ui (MIT, https://ui.shadcn.com/docs/components/card).
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../../lib/cn.ts';

export function Card({ className, ...props }: ComponentProps<'div'>): ReactNode {
    return (
        <div
            className={cn(
                'rounded-lg border-2 border-border bg-card text-card-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function CardHeader({ className, ...props }: ComponentProps<'div'>): ReactNode {
    return <div className={cn('flex flex-col gap-1 p-4 pb-2', className)} {...props} />;
}

export function CardTitle({ className, ...props }: ComponentProps<'h2'>): ReactNode {
    return <h2 className={cn('text-lg font-semibold leading-tight', className)} {...props} />;
}

export function CardContent({ className, ...props }: ComponentProps<'div'>): ReactNode {
    return <div className={cn('p-4 pt-2', className)} {...props} />;
}
