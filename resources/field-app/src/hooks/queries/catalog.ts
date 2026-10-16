/** Catalog queries (ADR 0035: agent B makes them read the offline cache first). */
import { queryOptions } from '@tanstack/react-query';
import { fetchForms, fetchFormVersion } from '../../lib/api/endpoints.ts';
import { MINUTE } from './shared.ts';

export const formsQueryOptions = (org: number | null) =>
    queryOptions({ queryKey: ['forms', org], queryFn: fetchForms, staleTime: MINUTE });

export const formVersionQueryOptions = (formId: number, versionId: number) =>
    queryOptions({
        queryKey: ['form-version', formId, versionId],
        queryFn: () => fetchFormVersion(formId, versionId),
        staleTime: Infinity,
    });
