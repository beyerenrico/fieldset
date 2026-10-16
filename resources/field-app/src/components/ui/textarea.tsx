// Adapted from shadcn/ui (MIT, https://ui.shadcn.com/docs/components/textarea).
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../../lib/cn.ts';

export function Textarea({ className, ...props }: ComponentProps<'textarea'>): ReactNode {
    return (
        <textarea
            className={cn(
                'flex min-h-28 w-full rounded-md border-2 border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        />
    );
}
