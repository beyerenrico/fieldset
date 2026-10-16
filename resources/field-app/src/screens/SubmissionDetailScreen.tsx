import { FormRenderer } from '@form-schema-app/form-schema-react';
import { useQuery } from '@tanstack/react-query';
import { getRouteApi, Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { ReviewNote } from '../components/ReviewNote.tsx';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { StatusBadge } from '../components/StatusBadge.tsx';
import { Alert, AlertTitle } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { formVersionQueryOptions, submissionQueryOptions } from '../hooks/queries.ts';
import { useApiAttachments } from '../hooks/useApiAttachments.ts';
import { useSession } from '../hooks/useSession.ts';
import { useI18n, useT } from '../i18n/I18nProvider.tsx';
import { EDITABLE_STATUSES, type AttachmentRecord, type Submission } from '../lib/api/types.ts';
import { buildEvaluationContext } from '../lib/session/eval-context.ts';
import { DocumentSection } from './detail/DocumentSection.tsx';
import { ReviewSection } from './detail/ReviewSection.tsx';

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

/** Changes requested (comment + resubmit), my open review task, or "continue editing". */
function ReviewState({ submission }: { readonly submission: Submission }): ReactNode {
    const t = useT();
    const { uuid, status } = submission;
    const editable = submission.can_edit && EDITABLE_STATUSES.includes(status);
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
    const submission = useQuery(submissionQueryOptions(uuid));
    const data = submission.data;
    const version = useQuery({
        ...formVersionQueryOptions(data?.form_id ?? 0, data?.form_version_id ?? 0),
        enabled: data !== undefined,
    });
    const attachments = useApiAttachments({
        submissionUuid: uuid,
        records: data?.attachments ?? NO_RECORDS,
        ensureSaved: readOnly,
    });

    if (submission.isPending) {
        return <Loading />;
    }
    if (submission.isError) {
        return <ErrorState error={submission.error} onRetry={() => void submission.refetch()} />;
    }
    const current = submission.data;
    return (
        <div className="flex flex-col gap-4">
            {submitted === true ? (
                <SubmittedNotice number={current.number} resubmitted={resubmitted === true} />
            ) : null}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-2xl font-bold">
                    {version.data?.definition.title ??
                        current.number ??
                        t('submitted.numberPending')}
                </h1>
                <span className="flex items-center gap-2">
                    <span className="sr-only">{t('detail.status')}</span>
                    <StatusBadge status={current.status} />
                </span>
            </div>
            {submitted === true ? null : (
                <p className="text-muted-foreground">
                    {current.number === null
                        ? t('submissions.noNumber')
                        : t('submitted.number', { number: current.number })}
                </p>
            )}
            <ReviewState submission={current} />
            <DocumentSection submission={current} definition={version.data?.definition} />
            {version.isPending ? <Loading /> : null}
            {version.isError ? (
                <ErrorState error={version.error} onRetry={() => void version.refetch()} />
            ) : null}
            {version.data !== undefined ? (
                <FormRenderer
                    definition={version.data.definition}
                    data={current.data}
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
