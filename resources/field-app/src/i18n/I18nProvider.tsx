import {
    createContext,
    use,
    useCallback,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { de } from './de.ts';
import { en, type MessageKey, type Messages } from './en.ts';
import {
    browserLanguage,
    loadPreferences,
    savePreferences,
    type Language,
    type Preferences,
} from './preferences.ts';

const catalogs: Readonly<Record<Language, Messages>> = { de, en };

export type Translate = (
    key: MessageKey,
    params?: Readonly<Record<string, string | number>>,
) => string;

export interface I18nValue {
    readonly language: Language;
    readonly preferences: Preferences;
    readonly t: Translate;
    /** Explicit language choice (Settings). */
    readonly setLanguage: (language: Language) => void;
    /** Fallback language from the user's profile, used while no explicit choice exists. */
    readonly setDefaultLanguage: (language: Language | undefined) => void;
    readonly setLargeText: (enabled: boolean) => void;
}

const I18nContext = createContext<I18nValue | null>(null);

export function formatMessage(
    template: string,
    params: Readonly<Record<string, string | number>> = {},
): string {
    return template.replace(/\{(\w+)\}/g, (match, name: string) =>
        Object.hasOwn(params, name) ? String(params[name]) : match,
    );
}

export function I18nProvider({ children }: { readonly children: ReactNode }): ReactNode {
    const [preferences, setPreferences] = useState<Preferences>(loadPreferences);
    const [fallback, setFallback] = useState<Language>(browserLanguage);
    const language = preferences.language ?? fallback;

    useEffect(() => {
        document.documentElement.lang = language;
    }, [language]);
    useEffect(() => {
        document.documentElement.classList.toggle('text-large', preferences.largeText);
    }, [preferences.largeText]);

    const update = useCallback((patch: Partial<Preferences>) => {
        setPreferences((current) => {
            const next = { ...current, ...patch };
            savePreferences(next);
            return next;
        });
    }, []);

    const value = useMemo<I18nValue>(() => {
        const catalog = catalogs[language];
        return {
            language,
            preferences,
            t: (key, params) => formatMessage(catalog[key], params),
            setLanguage: (next) => {
                update({ language: next });
            },
            setDefaultLanguage: (next) => {
                setFallback(next ?? browserLanguage());
            },
            setLargeText: (enabled) => {
                update({ largeText: enabled });
            },
        };
    }, [language, preferences, update]);

    return <I18nContext value={value}>{children}</I18nContext>;
}

export function useI18n(): I18nValue {
    const value = use(I18nContext);
    if (value === null) {
        throw new Error('useI18n must be used inside <I18nProvider>');
    }
    return value;
}

export function useT(): Translate {
    return useI18n().t;
}

export { catalogs };
export type { Language, MessageKey };
