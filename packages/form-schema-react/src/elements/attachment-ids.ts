import type { JsonValue } from '@form-schema-app/form-schema-js';

/** Attachment ids held by a photo/file value (single id or list). */
export function attachmentIds(value: JsonValue | undefined): string[] {
    if (typeof value === 'string' && value !== '') {
        return [value];
    }
    return Array.isArray(value)
        ? value.filter((item): item is string => typeof item === 'string')
        : [];
}

/** Message of a rejected upload: the adapter's error message, else the fallback. */
export function uploadErrorText(error: unknown, fallback: string): string {
    if (typeof error === 'object' && error !== null && 'message' in error) {
        const { message } = error;
        if (typeof message === 'string' && message !== '') {
            return message;
        }
    }
    return fallback;
}
