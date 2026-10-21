/** Per-element state: pointer, ids, display value, error and setters. */
import type { ElementNode, JsonValue } from '@fieldset/fieldset-js';
import { useContext } from 'react';
import { CellContext, useRenderer, type RendererContextValue } from '../context.tsx';
import { getAt, pointerId, toPointer, type DataPath } from '../data/path.ts';
import { errorMessage } from '../i18n/errors.ts';
import type { FieldError } from '../types.ts';

export interface FieldState {
    readonly node: ElementNode;
    readonly path: DataPath;
    readonly pointer: string;
    readonly id: string;
    readonly label: string;
    readonly help: string | undefined;
    readonly placeholder: string | undefined;
    readonly required: boolean;
    readonly disabled: boolean;
    /** Raw value, else the evaluated value (defaults). */
    readonly value: JsonValue | undefined;
    readonly error: FieldError | undefined;
    readonly errorText: string | undefined;
    readonly helpId: string;
    readonly errorId: string;
    readonly describedBy: string | undefined;
    readonly inCell: boolean;
    readonly context: RendererContextValue;
    readonly setValue: (value: JsonValue | undefined) => void;
    readonly touch: () => void;
}

function stringOf(node: ElementNode, key: string): string | undefined {
    const value = node.raw[key];
    return typeof value === 'string' && value !== '' ? value : undefined;
}

export function displayValue(context: RendererContextValue, path: DataPath): JsonValue | undefined {
    const raw = getAt(context.data, path);
    return raw === undefined || raw === null ? getAt(context.result.data, path) : raw;
}

export function useField(node: ElementNode, base: DataPath): FieldState {
    const context = useRenderer();
    const inCell = useContext(CellContext);
    const path = [...base, node.key];
    const pointer = toPointer(path);
    const id = pointerId(context.idBase, pointer);
    const error = context.visibleError(pointer);
    const help = stringOf(node, 'help');
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const describedBy = [help === undefined ? null : helpId, error === undefined ? null : errorId]
        .filter((part): part is string => part !== null)
        .join(' ');
    return {
        node,
        path,
        pointer,
        id,
        label: stringOf(node, 'label') ?? node.key,
        help,
        placeholder: stringOf(node, 'placeholder'),
        required: context.result.required[pointer] === true || context.hasRequiredError(pointer),
        disabled: context.isReadOnly(node, pointer),
        value: displayValue(context, path),
        error,
        errorText:
            error === undefined ? undefined : errorMessage(error, context.messages, context.locale),
        helpId,
        errorId,
        describedBy: describedBy === '' ? undefined : describedBy,
        inCell,
        context,
        setValue: (value) => {
            context.setValue(path, value);
        },
        touch: () => {
            context.touch(pointer);
        },
    };
}

/** ARIA and identity attributes shared by native inputs. */
export function inputAria(field: FieldState): {
    readonly id: string;
    readonly 'aria-invalid': boolean | undefined;
    readonly 'aria-describedby': string | undefined;
    readonly 'aria-required': boolean | undefined;
    readonly disabled: boolean;
} {
    return {
        id: field.id,
        'aria-invalid': field.error === undefined ? undefined : true,
        'aria-describedby': field.describedBy,
        'aria-required': field.required ? true : undefined,
        disabled: field.disabled,
    };
}
