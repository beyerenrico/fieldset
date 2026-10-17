/**
 * Loads a draft for fill/review, local-first (ADR 0035): the row on this device and the server
 * copy (when reachable) decide where the editor starts; offline, the local row is enough. The
 * form version comes from the catalog (cache first).
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { VersionLoadError } from '../components/VersionLoadError.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { formVersionQueryOptions, submissionQueryOptions } from '../hooks/queries.ts';
import { useDraftScope } from '../hooks/useLocalDrafts.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import { isApiError } from '../lib/api/errors.ts';
import type { FormVersion } from '../lib/api/types.ts';
import type { DraftRow, Scope } from '../lib/db/schema.ts';
import { chooseStart, readLocalDraft, type DraftStart } from '../lib/drafts/local-drafts.ts';

export interface DraftLoaderProps {
    readonly uuid: string;
    readonly formId?: number | undefined;
    readonly versionId?: number | undefined;
    readonly children: (start: DraftStart, version: FormVersion, scope: Scope) => ReactNode;
}

/** The local row when the screen opens (undefined while reading; no network involved). */
function useLocalRow(uuid: string, scope: Scope | null): DraftRow | null | undefined {
    const query = useQuery({
        queryKey: ['local-draft', uuid, scope?.orgId ?? null, scope?.userId ?? null],
        // A store that cannot be read (private mode, quota) must not hide the server copy.
        queryFn: () => (scope === null ? null : readLocalDraft(uuid, scope).catch(() => null)),
        networkMode: 'always',
        gcTime: 0,
        retry: false,
    });
    return query.data;
}

interface NoticeProps {
    readonly text: string;
    /** Link to the submission detail; without: to the catalog. */
    readonly detailUuid?: string;
}

function Notice({ text, detailUuid }: NoticeProps): ReactNode {
    const t = useT();
    return (
        <Alert role="alert">
            <p>{text}</p>
            <Button asChild variant="outline" className="mt-2">
                {detailUuid === undefined ? (
                    <Link to="/forms">{t('home.allForms')}</Link>
                ) : (
                    <Link to="/submissions/$uuid" params={{ uuid: detailUuid }}>
                        {t('fill.openDetail')}
                    </Link>
                )}
            </Button>
        </Alert>
    );
}

export function DraftLoader({ uuid, formId, versionId, children }: DraftLoaderProps): ReactNode {
    const t = useT();
    const scope = useDraftScope();
    const local = useLocalRow(uuid, scope);
    const submission = useQuery({ ...submissionQueryOptions(uuid), retry: false });
    const missing = isApiError(submission.error, 404);
    const server = submission.data ?? null;
    const start =
        local === undefined
            ? null
            : chooseStart(local, server, missing ? { formId, versionId } : {});
    const versionQuery = useQuery({
        ...formVersionQueryOptions(start?.formId ?? 0, start?.formVersionId ?? 0),
        enabled: start !== null,
    });

    if (local === undefined || submission.isPending) {
        return <Loading />;
    }
    if (submission.isError && !missing && local === null) {
        return <ErrorState error={submission.error} onRetry={() => void submission.refetch()} />;
    }
    if (start === null || scope === null) {
        return <Notice text={t('fill.missingForm')} />;
    }
    const dirty = local !== null && local.localVersion > local.syncedVersion;
    if (server !== null && !server.can_edit && !dirty) {
        return <Notice text={t('fill.locked')} detailUuid={uuid} />;
    }
    if (versionQuery.isPending) {
        return <Loading />;
    }
    if (versionQuery.isError) {
        return (
            <VersionLoadError
                error={versionQuery.error}
                onRetry={() => void versionQuery.refetch()}
            />
        );
    }
    return children(start, versionQuery.data, scope);
}
