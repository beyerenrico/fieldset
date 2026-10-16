/** Switches the active organization (`X-Organization-Id`) and drops every cache but the session. */
import { useQueryClient } from '@tanstack/react-query';
import { useCallback } from 'react';
import { getOrganizationId, setOrganizationId } from '../lib/session/org-store.ts';

export function useSwitchOrganization(): (id: number) => void {
    const queryClient = useQueryClient();
    return useCallback(
        (id: number) => {
            if (getOrganizationId() === id) {
                return;
            }
            setOrganizationId(id);
            // Query keys carry the organization, but nothing of the old one should linger.
            queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'me' });
        },
        [queryClient],
    );
}
