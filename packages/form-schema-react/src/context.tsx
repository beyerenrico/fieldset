/** Renderer context shared by every element component. */
import type {
    DefinitionModel,
    ElementNode,
    EvaluationResult,
    JsonObject,
    JsonValue,
} from '@form-schema-app/form-schema-js';
import { createContext, useContext } from 'react';
import type { DataPath } from './data/path.ts';
import type { RendererMessages } from './i18n/messages.ts';
import type { RevealRegistry } from './state/reveal.ts';
import type { AttachmentAdapter, FieldError, RendererMode } from './types.ts';

export type ValueUpdater = (current: JsonValue | undefined) => JsonValue | undefined;

export interface RendererContextValue {
    readonly model: DefinitionModel;
    /** Raw (controlled) data. */
    readonly data: JsonObject;
    readonly result: EvaluationResult;
    /** Definition locale (formatting). */
    readonly locale: string;
    readonly messages: RendererMessages;
    readonly mode: RendererMode;
    readonly idBase: string;
    readonly attachments: AttachmentAdapter;
    /** Sets a value (and emits `onChange`). */
    readonly setValue: (path: DataPath, value: JsonValue | undefined) => void;
    /** Updates a value from the latest data (safe for async completions). */
    readonly updateValue: (path: DataPath, updater: ValueUpdater) => void;
    readonly touch: (pointer: string) => void;
    /** The error to display for a pointer (respects touched state and `showErrors`). */
    readonly visibleError: (pointer: string) => FieldError | undefined;
    /** True when evaluate() reports `required` for the pointer (drives the marker for `requiredIf`). */
    readonly hasRequiredError: (pointer: string) => boolean;
    readonly isReadOnly: (node: ElementNode) => boolean;
    readonly reveal: RevealRegistry;
}

export const RendererContext = createContext<RendererContextValue | null>(null);

export function useRenderer(): RendererContextValue {
    const value = useContext(RendererContext);
    if (value === null) {
        throw new Error('Form elements must be rendered inside <FormRenderer>.');
    }
    return value;
}

/** True inside a repeater table cell: labels are visually hidden. */
export const CellContext = createContext(false);
