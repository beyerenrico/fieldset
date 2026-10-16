// Adapted from shadcn/ui (MIT, https://ui.shadcn.com/docs/components/button): 44 px targets.
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../../lib/cn.ts';

export const buttonVariants = cva(
    'inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-md text-base font-semibold transition-colors focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-5 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary/90',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
                outline: 'border-2 border-border bg-background hover:bg-muted',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                ghost: 'hover:bg-muted',
                link: 'text-primary underline underline-offset-4',
            },
            size: {
                default: 'px-4 py-2',
                lg: 'min-h-12 px-6 text-lg',
                icon: 'size-11',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

export interface ButtonProps extends ComponentProps<'button'>, VariantProps<typeof buttonVariants> {
    readonly asChild?: boolean;
}

export function Button({
    className,
    variant,
    size,
    asChild = false,
    ...props
}: ButtonProps): ReactNode {
    const Component = asChild ? Slot : 'button';
    return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
