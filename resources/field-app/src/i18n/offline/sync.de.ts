/** M6 sync texts, German (owned by agent A, ADR 0035). */
import type { syncEn } from './sync.en.ts';

export const syncDe: Readonly<Record<keyof typeof syncEn, string>> = {
    'sync.queued': 'Wartet auf Synchronisierung',
};
