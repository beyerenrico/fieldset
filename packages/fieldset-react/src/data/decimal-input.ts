/** Locale-tolerant parsing of decimal input into spec decimal strings (§5.1). */

const DECIMAL = /^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/;

export type DecimalInput =
    | { readonly kind: 'empty' }
    | { readonly kind: 'decimal'; readonly value: string }
    | { readonly kind: 'invalid'; readonly text: string };

export function isDecimalString(value: unknown): value is string {
    return typeof value === 'string' && DECIMAL.test(value);
}

function unifySeparators(body: string): string {
    const lastDot = body.lastIndexOf('.');
    const lastComma = body.lastIndexOf(',');
    if (lastDot >= 0 && lastComma >= 0) {
        const decimal = lastDot > lastComma ? '.' : ',';
        const grouping = decimal === '.' ? ',' : '.';
        return body.split(grouping).join('').replace(decimal, '.');
    }
    const separator = lastDot >= 0 ? '.' : lastComma >= 0 ? ',' : null;
    if (separator === null) {
        return body;
    }
    const parts = body.split(separator);
    // One separator is the decimal separator; repeated ones are grouping.
    return parts.length === 2 ? parts.join('.') : parts.join('');
}

/**
 * Parses user input: whitespace is dropped; with both `.` and `,` the last one is the decimal
 * separator; a single `.` or `,` is the decimal separator. Leading zeros are removed and `-0`
 * becomes `0`. Anything else is reported as `invalid` with the original text.
 */
export function parseDecimalInput(text: string): DecimalInput {
    const compact = text.replace(/\s/g, '');
    if (compact === '') {
        return { kind: 'empty' };
    }
    const negative = compact.startsWith('-');
    const body = unifySeparators(compact.replace(/^[-+]/, ''));
    const match = /^([0-9]*)(?:\.([0-9]*))?$/.exec(body);
    const integer = match?.[1] ?? '';
    const fraction = match?.[2] ?? '';
    if (match === null || (integer === '' && fraction === '')) {
        return { kind: 'invalid', text };
    }
    const digits = integer.replace(/^0+(?=[0-9])/, '') || '0';
    const unsigned = fraction === '' ? digits : `${digits}.${fraction}`;
    const isZero = /^0(\.0*)?$/.test(unsigned);
    const value = negative && !isZero ? `-${unsigned}` : unsigned;
    return DECIMAL.test(value) ? { kind: 'decimal', value } : { kind: 'invalid', text };
}

/** Shows a stored decimal string with the locale's decimal separator (no grouping). */
export function toInputText(value: unknown, decimalSeparator: string): string {
    if (typeof value !== 'string') {
        return typeof value === 'number' ? String(value) : '';
    }
    return isDecimalString(value) ? value.replace('.', decimalSeparator) : value;
}
