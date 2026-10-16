import { useQuery } from '@tanstack/react-query';
import { useSyncExternalStore } from 'react';
import type { Me, Organization } from '../lib/api/types.ts';
import { getOrganizationId, subscribeOrganization } from '../lib/session/org-store.ts';
import { meQueryOptions } from './queries.ts';

export function useOrganizationId(): number | null {
    return useSyncExternalStore(subscribeOrganization, getOrganizationId);
}

export interface Session {
    readonly me: Me;
    readonly organization: Organization | null;
}

/** Session inside the authenticated layout (the route guard has loaded `me`). */
export function useSession(): Session {
    const { data: me } = useQuery(meQueryOptions());
    const orgId = useOrganizationId();
    if (me === undefined) {
        throw new Error('useSession requires a loaded session');
    }
    const organization = me.organizations.find((candidate) => candidate.id === orgId) ?? null;
    return { me, organization };
}
