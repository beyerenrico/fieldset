/** Number, currency and date formatting with `Intl` in the definition locale. */

type NumericString = `${number}`;

const cache = new Map<string, Intl.NumberFormat>();

function numberFormat(locale: string, options: Intl.NumberFormatOptions): Intl.NumberFormat {
    const key = `${locale}|${JSON.stringify(options)}`;
    let format = cache.get(key);
    if (format === undefined) {
        try {
            format = new Intl.NumberFormat(locale, options);
        } catch {
            format = new Intl.NumberFormat('en', options);
        }
        cache.set(key, format);
    }
    return format;
}

function fractionDigits(value: string): number {
    const dot = value.indexOf('.');
    return dot < 0 ? 0 : value.length - dot - 1;
}

/** The locale's decimal separator (`,` for `de-DE`). */
export function decimalSeparator(locale: string): string {
    const part = numberFormat(locale, {})
        .formatToParts(1.5)
        .find((candidate) => candidate.type === 'decimal');
    return part?.value ?? '.';
}

/** Formats a decimal string keeping its exact digits (strings are not rounded through doubles). */
export function formatDecimal(value: string, locale: string): string {
    const digits = Math.min(fractionDigits(value), 100);
    return numberFormat(locale, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(value as NumericString);
}

/** Formats a decimal string as money in `currency`, keeping its exact digits. */
export function formatMoney(value: string, currency: string, locale: string): string {
    const digits = Math.min(fractionDigits(value), 100);
    try {
        return numberFormat(locale, {
            style: 'currency',
            currency,
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(value as NumericString);
    } catch {
        return `${formatDecimal(value, locale)} ${currency}`;
    }
}

/** Currency symbol and whether it precedes the amount in this locale. */
export function currencyAffix(
    currency: string,
    locale: string,
): { readonly symbol: string; readonly before: boolean } {
    try {
        const parts = numberFormat(locale, { style: 'currency', currency }).formatToParts(1);
        const symbolIndex = parts.findIndex((part) => part.type === 'currency');
        const numberIndex = parts.findIndex((part) => part.type === 'integer');
        return {
            symbol: parts[symbolIndex]?.value ?? currency,
            before: symbolIndex >= 0 && symbolIndex < numberIndex,
        };
    } catch {
        return { symbol: currency, before: false };
    }
}

/** Formats a plain number (coordinates, accuracy) with the given fraction digits. */
export function formatNumber(value: number, locale: string, maximumFractionDigits = 6): string {
    return numberFormat(locale, { maximumFractionDigits }).format(value);
}

/** Formats an RFC 3339 date-time for display; returns the input when it does not parse. */
export function formatDateTime(value: string, locale: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    try {
        return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(
            date,
        );
    } catch {
        return date.toISOString();
    }
}
