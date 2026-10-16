/** postMessage protocol between the builder (parent window) and the preview iframe. */
import type {
    EvaluationContext,
    FormDefinition,
    JsonObject,
} from '@form-schema-app/form-schema-js';

export const PREVIEW_MESSAGE = 'form-schema-preview';
export const PREVIEW_READY = 'form-schema-preview-ready';

export interface PreviewUpdate {
    readonly definition: FormDefinition;
    /** Replaces the preview data when present; otherwise the current entries are kept. */
    readonly data?: JsonObject;
    /** Overrides the sample evaluation context when present. */
    readonly context?: EvaluationContext;
}

export type PreviewParseResult =
    | { readonly kind: 'ignore' }
    | { readonly kind: 'invalid' }
    | { readonly kind: 'update'; readonly update: PreviewUpdate };

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function looksLikeDefinition(value: unknown): value is FormDefinition {
    return isObject(value) && Array.isArray(value.pages) && typeof value.locale === 'string';
}

/**
 * Accepts only `{ type: 'form-schema-preview', definition, data?, context? }` sent from the
 * preview's own origin. Messages from other origins or of other types are ignored.
 */
export function parsePreviewMessage(
    event: Pick<MessageEvent, 'origin' | 'data'>,
    expectedOrigin: string,
): PreviewParseResult {
    const message: unknown = event.data;
    if (event.origin !== expectedOrigin || !isObject(message)) {
        return { kind: 'ignore' };
    }
    if (message.type !== PREVIEW_MESSAGE) {
        return { kind: 'ignore' };
    }
    if (!looksLikeDefinition(message.definition)) {
        return { kind: 'invalid' };
    }
    const data = isObject(message.data) ? (message.data as JsonObject) : undefined;
    const context = isObject(message.context)
        ? (message.context as EvaluationContext)
        : undefined;
    return {
        kind: 'update',
        update: {
            definition: message.definition,
            ...(data === undefined ? {} : { data }),
            ...(context === undefined ? {} : { context }),
        },
    };
}
