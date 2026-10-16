// Adapted from shadcn/ui (MIT, https://ui.shadcn.com/docs/components/badge).
import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../../lib/cn.ts';

const badgeVariants = cva(
    'inline-flex items-center rounded-full border-2 px-2.5 py-0.5 text-sm font-semibold',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                outline: 'border-border text-foreground',
                success: 'border-transparent bg-success text-success-foreground',
                warning: 'border-transparent bg-warning text-warning-foreground',
                destructive: 'border-transparent bg-destructive text-destructive-foreground',
            },
        },
        defaultVariants: { variant: 'default' },
    },
);

export type BadgeVariant = NonNullable<VariantProps<typeof badgeVariants>['variant']>;

export function Badge({
    className,
    variant,
    ...props
}: ComponentProps<'span'> & VariantProps<typeof badgeVariants>): ReactNode {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
