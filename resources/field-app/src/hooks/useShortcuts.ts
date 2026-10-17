import { useNavigate } from '@tanstack/react-router';
import { useCallback, useEffect, useState } from 'react';
import type { FormSummary } from '../lib/api/types.ts';
import { onCatalogChanged } from '../lib/catalog/catalog.ts';
import { getFavorites, getRecent, recordRecent, toggleFavorite } from '../lib/forms/shortcuts.ts';
import { newId } from '../lib/ids.ts';
import { useCatalogSync } from './queries/catalog.ts';
import { useOrganizationId } from './useSession.ts';

export interface Shortcuts {
    readonly favorites: readonly number[];
    readonly recent: readonly number[];
    readonly toggle: (formId: number) => void;
}

interface StoredLists {
    readonly org: number;
    readonly favorites: readonly number[];
    readonly recent: readonly number[];
}

const readLists = (org: number): StoredLists => ({
    org,
    favorites: getFavorites(org),
    recent: getRecent(org),
});

/**
 * Favorites + recent forms of the current organization. The catalog cache refresh (started here
 * when stale) purges revoked forms from them through the manifest's `fillable_form_ids`.
 */
export function useShortcuts(): Shortcuts {
    const org = useOrganizationId();
    const key = org ?? 0;
    const [stored, setStored] = useState(() => readLists(key));
    useCatalogSync(org);

    useEffect(
        () =>
            onCatalogChanged((changed) => {
                if (changed === key) {
                    setStored(readLists(key));
                }
            }),
        [key],
    );

    const toggle = useCallback(
        (formId: number) => {
            toggleFavorite(key, formId);
            setStored(readLists(key));
        },
        [key],
    );
    // After an organization switch the stored lists belong to the previous one.
    const { favorites, recent } = stored.org === key ? stored : readLists(key);
    return { favorites, recent, toggle };
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
