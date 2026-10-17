import { FormRenderer } from '@form-schema-app/form-schema-react';
import { useQuery } from '@tanstack/react-query';
import { getRouteApi, Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { ReviewNote } from '../components/ReviewNote.tsx';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { StatusBadge } from '../components/StatusBadge.tsx';
import { VersionLoadError } from '../components/VersionLoadError.tsx';
import { Alert, AlertTitle } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { formVersionQueryOptions } from '../hooks/queries.ts';
import { useApiAttachments } from '../hooks/useApiAttachments.ts';
import { useSession } from '../hooks/useSession.ts';
import { useI18n, useT } from '../i18n/I18nProvider.tsx';
import { EDITABLE_STATUSES, type AttachmentRecord, type Submission } from '../lib/api/types.ts';
import type { DraftRow, DraftSync } from '../lib/db/schema.ts';
import { buildEvaluationContext } from '../lib/session/eval-context.ts';
import { DocumentSection } from './detail/DocumentSection.tsx';
import { ReviewSection } from './detail/ReviewSection.tsx';
import { SyncNotice } from './detail/SyncNotice.tsx';
import { useSubmissionView } from './detail/useSubmissionView.ts';
import { needsAttention } from './review/refusal.ts';

const route = getRouteApi('/_auth/submissions/$uuid');

const NO_RECORDS: readonly AttachmentRecord[] = [];
const readOnly = (): Promise<void> => Promise.reject(new Error('Submission is read-only'));

function SubmittedNotice({
    number,
    resubmitted,
}: {
    readonly number: string | null;
    readonly resubmitted: boolean;
}): ReactNode {
    const t = useT();
    return (
        <Alert variant="success" role="status">
            <AlertTitle>
                {resubmitted ? t('submitted.resubmittedTitle') : t('submitted.title')}
            </AlertTitle>
            <p>
                {number === null ? t('submitted.numberPending') : t('submitted.number', { number })}
            </p>
        </Alert>
    );
}

/** The badge: the state on this device while it matters (paused, queued), else the status. */
function syncBadge(row: DraftRow | null, queued: boolean): DraftSync | null {
    if (needsAttention(row)) {
        return row?.sync ?? null;
    }
    return queued ? 'queued' : null;
}

/**
 * Changes requested (comment + resubmit), my open review task, or "continue editing". `paused`:
 * queued or waiting for the user; the sync notice above leads on instead.
 */
function ReviewState({
    submission,
    paused,
}: {
    readonly submission: Submission;
    readonly paused: boolean;
}): ReactNode {
    const t = useT();
    const { uuid, status } = submission;
    const editable = !paused && submission.can_edit && EDITABLE_STATUSES.includes(status);
    const editLink = (label: string, className?: string): ReactNode => (
        <Button asChild size="lg" className={className}>
            <Link to="/fill/$uuid" params={{ uuid }}>
                {label}
            </Link>
        </Button>
    );
    return (
        <>
            {status === 'changes_requested' ? (
                <ReviewNote review={submission.review}>
                    {editable ? editLink(t('changes.editResubmit'), 'self-start') : null}
                </ReviewNote>
            ) : null}
            <ReviewSection submission={submission} />
            {editable && status !== 'changes_requested' ? editLink(t('detail.continue')) : null}
        </>
    );
}

export function SubmissionDetailScreen(): ReactNode {
    const { t, language } = useI18n();
    const { uuid } = route.useParams();
    const { submitted, resubmitted } = route.useSearch();
    const { me, organization } = useSession();
    const view = useSubmissionView(uuid);
    const data = view.submission;
    const version = useQuery({
        ...formVersionQueryOptions(data?.form_id ?? 0, data?.form_version_id ?? 0),
        enabled: data !== null,
    });
    const attachments = useApiAttachments({
        submissionUuid: uuid,
        records: data?.attachments ?? NO_RECORDS,
        ensureSaved: readOnly,
    });

    if (view.isPending) {
        return <Loading />;
    }
    if (data === null) {
        return <ErrorState error={view.error} onRetry={view.refetch} />;
    }
    const queued = view.queuedSubmit !== null;
    const badge = syncBadge(view.row, queued);
    return (
        <div className="flex flex-col gap-4">
            <SyncNotice row={view.row} queued={queued} lastError={view.lastError} />
            {submitted === true && !queued ? (
                <SubmittedNotice number={data.number} resubmitted={resubmitted === true} />
            ) : null}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-2xl font-bold">
                    {version.data?.definition.title ?? data.number ?? t('detail.title')}
                </h1>
                <span className="flex items-center gap-2">
                    <span className="sr-only">{t('detail.status')}</span>
                    {badge === null ? (
                        <StatusBadge status={data.status} />
                    ) : (
                        <StatusBadge sync={badge} />
                    )}
                </span>
            </div>
            {submitted === true && !queued ? null : (
                <p className="text-muted-foreground">
                    {data.number === null
                        ? t(queued ? 'sync.list.numberPending' : 'submissions.noNumber')
                        : t('submitted.number', { number: data.number })}
                </p>
            )}
            <ReviewState submission={data} paused={queued || needsAttention(view.row)} />
            <DocumentSection
                submission={data}
                definition={version.data?.definition}
                queuedSubmit={queued}
            />
            {version.isPending ? <Loading /> : null}
            {version.isError ? (
                <VersionLoadError error={version.error} onRetry={() => void version.refetch()} />
            ) : null}
            {version.data !== undefined ? (
                <FormRenderer
                    definition={version.data.definition}
                    data={data.data}
                    context={buildEvaluationContext(me, organization, version.data.version)}
                    attachments={attachments}
                    uiLocale={language}
                    layout="scroll"
                    readOnly
                />
            ) : null}
        </div>
    );
}
