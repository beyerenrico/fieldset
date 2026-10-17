/**
 * postMessage protocol between the builder (parent window) and the preview iframe (ADR 0040).
 *
 * The preview runs sandboxed without `allow-same-origin` (iframe attribute and CSP `sandbox`), so its
 * document has an opaque origin: it cannot reach the admin's cookies, DOM or the Field App's IndexedDB.
 *
 * - builder → preview: `{ type: PREVIEW_MESSAGE, definition, data?, context? }` posted to the iframe's
 *   window with target origin `PREVIEW_TARGET_ORIGIN` ('*'): no origin string can name an opaque origin,
 *   so delivery is pinned by the receiving window object instead.
 * - preview → builder: `{ type: PREVIEW_READY }` posted to `window.parent` with the builder's origin
 *   (`location.origin` is still the URL's origin inside the sandbox). The builder trusts it only when
 *   `event.source` is its iframe's window and `event.origin` is `PREVIEW_SENDER_ORIGIN` ('null').
 * - The preview trusts updates only from `window.parent` with the builder's origin (parsePreviewMessage).
 */
import type {
    EvaluationContext,
    FormDefinition,
    JsonObject,
} from '@form-schema-app/form-schema-js';

export const PREVIEW_MESSAGE = 'form-schema-preview';
export const PREVIEW_READY = 'form-schema-preview-ready';
/** Target origin of builder → preview messages (the preview's origin is opaque). */
export const PREVIEW_TARGET_ORIGIN = '*';
/** `event.origin` of preview → builder messages: the serialization of an opaque origin. */
export const PREVIEW_SENDER_ORIGIN = 'null';

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
 * Builder side: true only for the ready message of the given preview iframe window, sent from the
 * sandbox (opaque origin). A same-origin sender means the sandbox is missing, so it is refused too.
 */
export function isPreviewReady(
    event: Pick<MessageEvent, 'origin' | 'data' | 'source'>,
    frameWindow: MessageEventSource | null,
): boolean {
    const message: unknown = event.data;
    return (
        frameWindow !== null &&
        event.source === frameWindow &&
        event.origin === PREVIEW_SENDER_ORIGIN &&
        isObject(message) &&
        message.type === PREVIEW_READY
    );
}

/**
 * Preview side: accepts only `{ type: 'form-schema-preview', definition, data?, context? }` sent by
 * the parent window (the builder) from the builder's origin. Messages from other origins, other
 * windows, or of other types are ignored.
 */
export function parsePreviewMessage(
    event: Pick<MessageEvent, 'origin' | 'data' | 'source'>,
    expectedOrigin: string,
    expectedSource: MessageEventSource | null,
): PreviewParseResult {
    const message: unknown = event.data;
    if (
        event.origin !== expectedOrigin ||
        event.source === null ||
        event.source !== expectedSource ||
        !isObject(message)
    ) {
        return { kind: 'ignore' };
    }
    if (message.type !== PREVIEW_MESSAGE) {
        return { kind: 'ignore' };
    }
    if (!looksLikeDefinition(message.definition)) {
        return { kind: 'invalid' };
    }
    const data = isObject(message.data) ? (message.data as JsonObject) : undefined;
    const context = isObject(message.context) ? (message.context as EvaluationContext) : undefined;
    return {
        kind: 'update',
        update: {
            definition: message.definition,
            ...(data === undefined ? {} : { data }),
            ...(context === undefined ? {} : { context }),
        },
    };
}
