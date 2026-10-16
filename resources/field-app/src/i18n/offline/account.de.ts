/** M6 account texts, German (owned by agent C, ADR 0035). */
import type { accountEn } from './account.en.ts';

export const accountDe: Readonly<Record<keyof typeof accountEn, string>> = {
    'account.storage': 'Speicher auf diesem Gerät',
};
