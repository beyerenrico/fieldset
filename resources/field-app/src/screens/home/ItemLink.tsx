/** A list item's link: the form, its review (paused items: decide or fix) or the detail. */
import { Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';

export type ItemTarget = 'fill' | 'review' | 'detail';

export interface ItemLinkProps {
    readonly uuid: string;
    readonly target: ItemTarget;
    readonly className: string;
    readonly children: ReactNode;
}

export function ItemLink({ uuid, target, className, children }: ItemLinkProps): ReactNode {
    switch (target) {
        case 'review':
            return (
                <Link to="/fill/$uuid/review" params={{ uuid }} className={className}>
                    {children}
                </Link>
            );
        case 'fill':
            return (
                <Link to="/fill/$uuid" params={{ uuid }} className={className}>
                    {children}
                </Link>
            );
        case 'detail':
            return (
                <Link to="/submissions/$uuid" params={{ uuid }} className={className}>
                    {children}
                </Link>
            );
    }
}
