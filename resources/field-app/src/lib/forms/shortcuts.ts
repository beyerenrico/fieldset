/** Favorites and recently used forms, stored per organization in localStorage. */
import { readJson, writeJson } from '../storage/local.ts';

const RECENT_LIMIT = 6;

const favoritesKey = (org: number): string => `favorites.${String(org)}`;
const recentKey = (org: number): string => `recent.${String(org)}`;

function readIds(key: string): number[] {
    const value = readJson<unknown>(key, []);
    return Array.isArray(value)
        ? value.filter((item): item is number => typeof item === 'number')
        : [];
}

export function getFavorites(org: number): number[] {
    return readIds(favoritesKey(org));
}

export function toggleFavorite(org: number, formId: number): number[] {
    const current = getFavorites(org);
    const next = current.includes(formId)
        ? current.filter((id) => id !== formId)
        : [...current, formId];
    writeJson(favoritesKey(org), next);
    return next;
}

export function getRecent(org: number): number[] {
    return readIds(recentKey(org));
}

export function recordRecent(org: number, formId: number): number[] {
    const next = [formId, ...getRecent(org).filter((id) => id !== formId)].slice(0, RECENT_LIMIT);
    writeJson(recentKey(org), next);
    return next;
}

/** Drops forms the user may no longer fill (`fillable_form_ids` is the complete list). */
export function purgeShortcuts(org: number, fillable: readonly number[]): void {
    const allowed = new Set(fillable);
    writeJson(
        favoritesKey(org),
        getFavorites(org).filter((id) => allowed.has(id)),
    );
    writeJson(
        recentKey(org),
        getRecent(org).filter((id) => allowed.has(id)),
    );
}
