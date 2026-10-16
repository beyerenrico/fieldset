/** UI preferences (language, larger text), persisted in localStorage; they survive logout. */
import { readJson, writeJson } from '../lib/storage/local.ts';

export type Language = 'de' | 'en';

export interface Preferences {
    /** Explicit choice; `undefined` follows the user's locale or the browser. */
    readonly language?: Language;
    readonly largeText: boolean;
}

const KEY = 'settings';

export function toLanguage(locale: string | null | undefined): Language | undefined {
    const base = locale?.toLowerCase().split(/[-_]/)[0];
    return base === 'de' || base === 'en' ? base : undefined;
}

export function loadPreferences(): Preferences {
    const stored = readJson<Partial<Preferences> | null>(KEY, null);
    const language = toLanguage(stored?.language);
    return {
        largeText: stored?.largeText === true,
        ...(language === undefined ? {} : { language }),
    };
}

export function savePreferences(preferences: Preferences): void {
    writeJson(KEY, preferences);
}

export function browserLanguage(): Language {
    return toLanguage(typeof navigator === 'undefined' ? undefined : navigator.language) ?? 'en';
}
