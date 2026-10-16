import { FormRenderer } from '@form-schema-app/form-schema-react';
import { useQuery } from '@tanstack/react-query';
import { getRouteApi, Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { StatusBadge } from '../components/StatusBadge.tsx';
import { Alert, AlertTitle } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { formVersionQueryOptions, submissionQueryOptions } from '../hooks/queries.ts';
import { useApiAttachments } from '../hooks/useApiAttachments.ts';
import { useSession } from '../hooks/useSession.ts';
import { useI18n } from '../i18n/I18nProvider.tsx';
import { EDITABLE_STATUSES, type AttachmentRecord } from '../lib/api/types.ts';
import { buildEvaluationContext } from '../lib/session/eval-context.ts';
import { DocumentSection } from './detail/DocumentSection.tsx';

const route = getRouteApi('/_auth/submissions/$uuid');

const NO_RECORDS: readonly AttachmentRecord[] = [];
const readOnly = (): Promise<void> => Promise.reject(new Error('Submission is read-only'));

export function SubmissionDetailScreen(): ReactNode {
    const { t, language } = useI18n();
    const { uuid } = route.useParams();
    const { submitted } = route.useSearch();
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
    const editable = current.can_edit && EDITABLE_STATUSES.includes(current.status);
    return (
        <div className="flex flex-col gap-4">
            {submitted === true ? (
                <Alert variant="success" role="status">
                    <AlertTitle>{t('submitted.title')}</AlertTitle>
                    <p>
                        {current.number === null
                            ? t('submitted.numberPending')
                            : t('submitted.number', { number: current.number })}
                    </p>
                </Alert>
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
            {editable ? (
                <Button asChild size="lg">
                    <Link to="/fill/$uuid" params={{ uuid }}>
                        {t('detail.continue')}
                    </Link>
                </Button>
            ) : null}
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
