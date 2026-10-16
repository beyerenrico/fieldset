/** Loads the submission (or a new draft's form version) and the definition for fill/review. */
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { formVersionQueryOptions, submissionQueryOptions } from '../hooks/queries.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import { isApiError } from '../lib/api/errors.ts';
import type { FormVersion, Submission } from '../lib/api/types.ts';

export interface DraftLoaderProps {
    readonly uuid: string;
    readonly formId?: number | undefined;
    readonly versionId?: number | undefined;
    readonly children: (submission: Submission | null, version: FormVersion) => ReactNode;
}

export function DraftLoader({ uuid, formId, versionId, children }: DraftLoaderProps): ReactNode {
    const t = useT();
    const submission = useQuery({ ...submissionQueryOptions(uuid), retry: false });
    const isNew = isApiError(submission.error, 404);
    const form = submission.data?.form_id ?? (isNew ? formId : undefined);
    const version = submission.data?.form_version_id ?? (isNew ? versionId : undefined);
    const versionQuery = useQuery({
        ...formVersionQueryOptions(form ?? 0, version ?? 0),
        enabled: form !== undefined && version !== undefined,
    });

    if (submission.isPending) {
        return <Loading />;
    }
    if (submission.isError && !isNew) {
        return <ErrorState error={submission.error} onRetry={() => void submission.refetch()} />;
    }
    if (form === undefined || version === undefined) {
        return (
            <Alert role="alert">
                <p>{t('fill.missingForm')}</p>
                <Button asChild variant="outline" className="mt-2">
                    <Link to="/forms">{t('home.allForms')}</Link>
                </Button>
            </Alert>
        );
    }
    if (submission.data !== undefined && !submission.data.can_edit) {
        return (
            <Alert role="alert">
                <p>{t('fill.locked')}</p>
                <Button asChild variant="outline" className="mt-2">
                    <Link to="/submissions/$uuid" params={{ uuid }}>
                        {t('fill.openDetail')}
                    </Link>
                </Button>
            </Alert>
        );
    }
    if (versionQuery.isPending) {
        return <Loading />;
    }
    if (versionQuery.isError) {
        return (
            <ErrorState error={versionQuery.error} onRetry={() => void versionQuery.refetch()} />
        );
    }
    return children(submission.data ?? null, versionQuery.data);
}
