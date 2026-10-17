import type { ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import type { SubmissionStatus } from '../lib/api/types.ts';
import type { DraftSync } from '../lib/db/schema.ts';
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

const SYNC_VARIANTS: Readonly<Record<DraftSync, BadgeVariant>> = {
    local: 'outline',
    queued: 'secondary',
    syncing: 'secondary',
    synced: 'outline',
    conflict: 'destructive',
    blocked: 'destructive',
};

export type StatusBadgeProps =
    | { readonly status: SubmissionStatus; readonly sync?: undefined }
    /** Sync state of an item on this device (ADR 0035), e.g. "Waiting to sync". */
    | { readonly sync: DraftSync; readonly status?: undefined };

export function StatusBadge(props: StatusBadgeProps): ReactNode {
    const t = useT();
    if (props.sync !== undefined) {
        return (
            <Badge variant={SYNC_VARIANTS[props.sync]} data-sync={props.sync}>
                {t(`sync.state.${props.sync}`)}
            </Badge>
        );
    }
    return <Badge variant={VARIANTS[props.status]}>{t(`status.${props.status}`)}</Badge>;
}
