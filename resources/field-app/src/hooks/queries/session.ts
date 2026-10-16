/** Session queries (ADR 0035: agent C may add an offline fallback to the cached `me`). */
import { queryOptions } from '@tanstack/react-query';
import { fetchMe } from '../../lib/api/endpoints.ts';
import { MINUTE } from './shared.ts';

export const meQueryOptions = () =>
    queryOptions({ queryKey: ['me'], queryFn: fetchMe, staleTime: 5 * MINUTE });
