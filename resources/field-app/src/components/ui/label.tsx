// Adapted from shadcn/ui (MIT, https://ui.shadcn.com/docs/components/label), native <label>.
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../../lib/cn.ts';

export function Label({ className, ...props }: ComponentProps<'label'>): ReactNode {
    return <label className={cn('text-base font-semibold leading-tight', className)} {...props} />;
}
