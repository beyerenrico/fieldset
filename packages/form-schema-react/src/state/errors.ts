/** Error bookkeeping: first error per pointer and document-ordered entries for the summary. */
import type { ValidationError } from '@form-schema-app/form-schema-js';
import type { RendererMessages } from '../i18n/messages.ts';
import { rowLabel } from '../repeater/row-label.ts';
import type { FieldInstance } from '../structure/instances.ts';
import type { FieldError } from '../types.ts';

export interface ErrorEntry {
    readonly pointer: string;
    readonly error: FieldError;
    /** Page index in `model.pages`; `null` when the pointer matches no rendered instance. */
    readonly page: number | null;
    readonly label: string;
}

export function firstByPointer(errors: readonly FieldError[]): ReadonlyMap<string, FieldError> {
    const map = new Map<string, FieldError>();
    for (const error of errors) {
        if (!map.has(error.pointer)) {
            map.set(error.pointer, error);
        }
    }
    return map;
}

function instanceLabel(instance: FieldInstance, messages: RendererMessages): string {
    const { node, path } = instance;
    const label = typeof node.raw['label'] === 'string' ? node.raw['label'] : node.key;
    const repeater = node.scope.repeater;
    const index = path[path.length - 2];
    if (repeater === null || typeof index !== 'number') {
        return label;
    }
    return `${label} (${rowLabel(repeater, index, messages)})`;
}

/**
 * Entries in document order: client errors of visible instances, external errors (which win for
 * the same pointer); unknown pointers go last.
 */
export function collectErrors(
    instances: readonly FieldInstance[],
    visibility: Readonly<Record<string, boolean>>,
    client: readonly ValidationError[],
    external: readonly FieldError[],
    messages: RendererMessages,
): ErrorEntry[] {
    const merged = new Map(firstByPointer(client));
    for (const [pointer, error] of firstByPointer(external)) {
        merged.set(pointer, error);
    }
    const externalPointers = new Set(external.map((error) => error.pointer));
    const entries: ErrorEntry[] = [];
    for (const instance of instances) {
        const error = merged.get(instance.pointer);
        if (error === undefined) {
            continue;
        }
        merged.delete(instance.pointer);
        if (visibility[instance.pointer] === true || externalPointers.has(instance.pointer)) {
            entries.push({
                pointer: instance.pointer,
                error,
                page: instance.page,
                label: instanceLabel(instance, messages),
            });
        }
    }
    for (const [pointer, error] of merged) {
        if (externalPointers.has(pointer)) {
            entries.push({ pointer, error, page: null, label: pointer });
        }
    }
    return entries;
}
