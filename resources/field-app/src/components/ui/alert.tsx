// Adapted from shadcn/ui (MIT, https://ui.shadcn.com/docs/components/alert).
import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../../lib/cn.ts';

const alertVariants = cva('relative w-full rounded-lg border-2 p-4 text-base', {
    variants: {
        variant: {
            default: 'border-border bg-card text-card-foreground',
            info: 'border-primary bg-primary/10 text-foreground',
            destructive: 'border-destructive bg-destructive/10 text-foreground',
            success: 'border-success bg-success/10 text-foreground',
            warning: 'border-warning bg-warning/15 text-foreground',
        },
    },
    defaultVariants: { variant: 'default' },
});

export function Alert({
    className,
    variant,
    ...props
}: ComponentProps<'div'> & VariantProps<typeof alertVariants>): ReactNode {
    return <div className={cn(alertVariants({ variant }), className)} {...props} />;
}

export function AlertTitle({ className, ...props }: ComponentProps<'p'>): ReactNode {
    return <p className={cn('mb-1 font-semibold', className)} {...props} />;
}
