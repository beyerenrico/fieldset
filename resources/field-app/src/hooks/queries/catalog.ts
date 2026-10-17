/** Catalog queries: the offline cache first, the network second (ADR 0035). */
import { queryOptions, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { ApiError, isAuthLost } from '../../lib/api/errors.ts';
import {
    loadForms,
    loadFormVersion,
    NotCachedError,
    onCatalogChanged,
    refreshCatalogIfStale,
    reportRefreshError,
} from '../../lib/catalog/catalog.ts';
import { MINUTE } from './shared.ts';

const MAX_RETRIES = 2;

/** Like App's shouldRetry, plus no retries for "not available offline". */
export function catalogRetry(failureCount: number, error: unknown): boolean {
    if (
        error instanceof NotCachedError ||
        isAuthLost(error) ||
        (error instanceof ApiError && error.status < 500)
    ) {
        return false;
    }
    return failureCount < MAX_RETRIES;
}

/** `offlineFirst`: the query function runs while offline (it reads the cache). */
const offlineFirst = { networkMode: 'offlineFirst', retry: catalogRetry } as const;

export const formsQueryOptions = (org: number | null) =>
    queryOptions({
        queryKey: ['forms', org],
        queryFn: () => loadForms(org),
        staleTime: MINUTE,
        ...offlineFirst,
    });

export const formVersionQueryOptions = (formId: number, versionId: number) =>
    queryOptions({
        queryKey: ['form-version', formId, versionId],
        queryFn: () => loadFormVersion(formId, versionId),
        staleTime: Infinity,
        ...offlineFirst,
    });

/** Refreshes a stale catalog on mount and refetches the forms list whenever the cache changes. */
export function useCatalogSync(org: number | null): void {
    const queryClient = useQueryClient();
    useEffect(() => {
        if (org === null) {
            return undefined;
        }
        const stop = onCatalogChanged((changed, change) => {
            if (changed === org && change === 'forms') {
                void queryClient.invalidateQueries({ queryKey: ['forms', org] });
            }
        });
        refreshCatalogIfStale(org).catch(reportRefreshError);
        return stop;
    }, [org, queryClient]);
}
