import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import type { SubmissionStatus } from '../lib/api/types.ts';
import { Badge, type BadgeVariant } from './ui/badge.tsx';

const VARIANTS: Readonly<Record<SubmissionStatus, BadgeVariant>> = {
    draft: 'outline',
    submitted: 'default',
    in_review: 'secondary',
    changes_requested: 'warning',
    approved: 'success',
    completed: 'success',
    void: 'destructive',
};

export function StatusBadge({ status }: { readonly status: SubmissionStatus }): ReactNode {
    const t = useT();
    return <Badge variant={VARIANTS[status]}>{t(`status.${status}`)}</Badge>;
}
