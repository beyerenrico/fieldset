/** Session queries: `me` falls back to the cached session when the server is unreachable (ADR 0035). */
import { queryOptions } from '@tanstack/react-query';
import { ApiError } from '../../lib/api/errors.ts';
import { fetchSessionMe, isCachedSession, isUnreachable } from '../../lib/session/offline-start.ts';
import { MINUTE } from './shared.ts';

const MAX_RETRIES = 2;

/** Unreachable without a cached session: show "offline" at once instead of retrying for seconds. */
function retryMe(failureCount: number, error: unknown): boolean {
    return (
        error instanceof ApiError &&
        error.status >= 500 &&
        !isUnreachable(error) &&
        failureCount < MAX_RETRIES
    );
}

export const meQueryOptions = () =>
    queryOptions({
        queryKey: ['me'],
        queryFn: fetchSessionMe,
        // A cached session is stale at once: the next mount or reconnect asks the server again.
        staleTime: (query) => (isCachedSession(query.state.data) ? 0 : 5 * MINUTE),
        // Offline the query must still run (and fall back), not pause until the network returns.
        networkMode: 'always',
        retry: retryMe,
    });
