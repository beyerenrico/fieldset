import { useQuery } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { fetchSyncManifest } from '../lib/api/endpoints.ts';
import type { FormSummary } from '../lib/api/types.ts';
import {
    getFavorites,
    getRecent,
    purgeShortcuts,
    recordRecent,
    toggleFavorite,
} from '../lib/forms/shortcuts.ts';
import { newId } from '../lib/ids.ts';
import { useOrganizationId } from './useSession.ts';

export interface Shortcuts {
    readonly favorites: readonly number[];
    readonly recent: readonly number[];
    readonly toggle: (formId: number) => void;
}

const keepAllowed = (ids: readonly number[], allowed: ReadonlySet<number> | null): number[] =>
    allowed === null ? [...ids] : ids.filter((id) => allowed.has(id));

/** Favorites + recent forms of the current organization, purged by the sync manifest. */
export function useShortcuts(): Shortcuts {
    const org = useOrganizationId() ?? 0;
    const [favorites, setFavorites] = useState(() => getFavorites(org));
    const [recent] = useState(() => getRecent(org));
    const manifest = useQuery({
        queryKey: ['manifest', org],
        queryFn: () => fetchSyncManifest(),
        staleTime: 60_000,
    });
    const fillable = manifest.data?.fillable_form_ids;
    const allowed = useMemo(() => (fillable === undefined ? null : new Set(fillable)), [fillable]);

    useEffect(() => {
        if (fillable !== undefined) {
            purgeShortcuts(org, fillable);
        }
    }, [org, fillable]);

    const toggle = useCallback(
        (formId: number) => {
            setFavorites(toggleFavorite(org, formId));
        },
        [org],
    );
    return {
        favorites: keepAllowed(favorites, allowed),
        recent: keepAllowed(recent, allowed),
        toggle,
    };
}

/** Starts a new draft: client UUIDv7, pinned to the form's current version. */
export function useStartForm(): (form: FormSummary) => void {
    const navigate = useNavigate();
    const org = useOrganizationId();
    return useCallback(
        (form: FormSummary) => {
            if (org !== null) {
                recordRecent(org, form.id);
            }
            void navigate({
                to: '/fill/$uuid',
                params: { uuid: newId() },
                search: { formId: form.id, versionId: form.version.id },
            });
        },
        [navigate, org],
    );
}
