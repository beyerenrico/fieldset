import type { Me, Organization } from '../api/types.ts';
import { getOrganizationId, setOrganizationId } from './org-store.ts';

/** The stored organization if the user still belongs to it; a single membership is auto-selected. */
export function resolveOrganization(me: Me): Organization | null {
    const stored = getOrganizationId();
    const match = me.organizations.find((organization) => organization.id === stored);
    if (match !== undefined) {
        return match;
    }
    const only = me.organizations.length === 1 ? me.organizations[0] : undefined;
    if (only !== undefined) {
        setOrganizationId(only.id);
        return only;
    }
    if (stored !== null) {
        setOrganizationId(null);
    }
    return null;
}
