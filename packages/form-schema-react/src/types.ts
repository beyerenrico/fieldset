/** Public types of the React renderer. */
import type {
    DefinitionModel,
    ElementType,
    EvaluationContext,
    EvaluationResult,
    FormDefinition,
    JsonObject,
    JsonValue,
} from '@form-schema-app/form-schema-js';
import type { RendererMessages } from './i18n/messages.ts';

/** `fill` for real data entry, `preview` for the builder preview (shows a banner, never persists). */
export type RendererMode = 'fill' | 'preview';

/** Overrides `settings.layout` of the definition. */
export type RendererLayout = 'wizard' | 'scroll';

export type AttachmentKind = 'photo' | 'file' | 'signature';

/** A file the renderer wants stored (already compressed for photos, PNG for signatures). */
export interface AttachmentUpload {
    readonly file: Blob;
    readonly fileName: string;
    readonly kind: AttachmentKind;
    /** Key of the photo, file or signature element. */
    readonly elementKey: string;
    /** JSON Pointer of the value in the submission data, e.g. `/items/0/photo`. */
    readonly pointer: string;
}

/** A stored attachment. `id` is the UUID written into the submission data. */
export interface AttachmentInfo {
    readonly id: string;
    readonly fileName?: string;
    readonly mime?: string;
    readonly size?: number;
    /** Displayable URL (object URL, data URL or same-origin URL) for thumbnails and links. */
    readonly url?: string;
}

/**
 * The renderer never talks to the network. Hosts inject an adapter: the Field App uploads to the
 * API (later: queues in IndexedDB), the builder preview keeps files in memory.
 */
export interface AttachmentAdapter {
    /** Stores the file and resolves to the attachment (its `id` becomes the field value). */
    upload(request: AttachmentUpload): Promise<AttachmentInfo>;
    /** Describes an attachment referenced by the data (e.g. after a reload); optional. */
    get?(id: string): AttachmentInfo | undefined;
    /** Called when the user removes an attachment from a field; optional. */
    remove?(id: string, request: Omit<AttachmentUpload, 'file' | 'fileName'>): void | Promise<void>;
}

/**
 * An error shown next to a field. Client errors come from `evaluate()`; hosts pass extra ones
 * (e.g. the server's `validation_failed` details) through `errors`.
 */
export interface FieldError {
    /** JSON Pointer into the submission data, e.g. `/items/2/qty`. */
    readonly pointer: string;
    /** Spec error code (§5.6) or a host code. */
    readonly code: string;
    readonly params?: Readonly<Record<string, JsonValue>>;
    /** Pre-localized text; wins over the message for `code`. */
    readonly message?: string;
}

/** The single primary action of the screen (sticky footer), e.g. "Review" or "Check". */
export interface PrimaryAction {
    readonly label: string;
    readonly onClick: () => void;
    readonly disabled?: boolean;
    readonly busy?: boolean;
}

export interface FormRendererProps {
    /** Raw definition JSON or a model from `parseDefinition`. */
    readonly definition: FormDefinition | DefinitionModel;
    /** Current submission data (controlled). */
    readonly data: JsonObject;
    /** Built-ins for `evaluate()`: today, now, user, org, formVersion. */
    readonly context?: EvaluationContext;
    /** Called after every edit with the next data (user input plus applied defaults). */
    readonly onChange?: (data: JsonObject, result: EvaluationResult) => void;
    /** Extra errors by pointer (always shown), e.g. from a server 422. */
    readonly errors?: readonly FieldError[];
    readonly mode?: RendererMode;
    readonly layout?: RendererLayout;
    readonly attachments: AttachmentAdapter;
    /** Disables every input. */
    readonly readOnly?: boolean;
    /** With `readOnly`: element types that stay editable (e.g. `['signature']` on a review screen). */
    readonly readOnlyExcept?: readonly ElementType[];
    /** Shows all client errors and the error summary (e.g. after a submit attempt). */
    readonly showErrors?: boolean;
    /** Sticky primary action; in wizard layout it replaces "Next" on the last page. */
    readonly primaryAction?: PrimaryAction;
    /** Language of the renderer's own texts (`de`, `en`, …); defaults to the definition locale. */
    readonly uiLocale?: string;
    /** Overrides for the renderer's own texts. */
    readonly messages?: Partial<RendererMessages>;
    /** Called with every new evaluation result (visibility, data, errors). */
    readonly onEvaluate?: (result: EvaluationResult) => void;
    /** Wizard layout: called when the visible page changes. */
    readonly onPageChange?: (index: number, count: number) => void;
    /** Prefix for DOM ids, needed when several renderers share a page. */
    readonly idPrefix?: string;
    readonly className?: string;
}

export type { RendererMessages };
