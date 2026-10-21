/** Localized error texts for spec error codes (§5.6) and host errors. */
import type { JsonValue } from '@fieldset/fieldset-js';
import { isDecimalString } from '../data/decimal-input.ts';
import { formatDecimal } from '../format/intl.ts';
import type { FieldError } from '../types.ts';
import { interpolate, type MessageKey, type RendererMessages } from './messages.ts';

const ERROR_KEYS: ReadonlySet<string> = new Set<MessageKey>([
    'required',
    'type_mismatch',
    'decimal_string_required',
    'invalid_number',
    'scale_exceeded',
    'invalid_email',
    'invalid_phone',
    'invalid_date',
    'invalid_time',
    'invalid_datetime',
    'invalid_option',
    'invalid_attachment',
    'invalid_signature',
    'invalid_location',
    'min_length',
    'max_length',
    'pattern',
    'min',
    'max',
    'step',
    'min_items',
    'max_items',
    'out_of_range',
]);

function isErrorKey(code: string): code is MessageKey {
    return ERROR_KEYS.has(code);
}

function formatParam(value: JsonValue, locale: string): string {
    if (typeof value === 'number') {
        return formatDecimal(String(value), locale);
    }
    if (isDecimalString(value)) {
        return formatDecimal(value, locale);
    }
    return typeof value === 'string' ? value : JSON.stringify(value);
}

/** Message for an error: its own `message`, the text for its code, or a generic fallback. */
export function errorMessage(
    error: FieldError,
    messages: RendererMessages,
    locale: string,
): string {
    if (error.message !== undefined && error.message !== '') {
        return error.message;
    }
    const template = isErrorKey(error.code) ? messages[error.code] : messages.unknownError;
    const params = Object.fromEntries(
        Object.entries(error.params ?? {}).map(([name, value]) => [
            name,
            formatParam(value, locale),
        ]),
    );
    return interpolate(template, params);
}
