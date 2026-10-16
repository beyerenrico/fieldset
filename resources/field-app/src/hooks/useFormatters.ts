import { useMemo } from 'react';
import { useI18n } from '../i18n/I18nProvider.tsx';

export interface Formatters {
    readonly dateTime: (value: string | null | undefined) => string;
}

/** Intl formatting in the UI language. */
export function useFormatters(): Formatters {
    const { language, t } = useI18n();
    return useMemo(() => {
        const format = new Intl.DateTimeFormat(language, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
        return {
            dateTime: (value) => {
                const date = value === null || value === undefined ? null : new Date(value);
                return date === null || Number.isNaN(date.getTime())
                    ? t('common.empty')
                    : format.format(date);
            },
        };
    }, [language, t]);
}
