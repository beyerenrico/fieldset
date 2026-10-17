/**
 * Key suggestions in the Builder v2 inspector (ADR 0037). The server never writes the key field when
 * a label changes: Livewire would replace a key typed while that request was in flight. It sends
 * `builder-v2:key-suggested {id, key, from}` instead, and the client fills the key field only while
 * it still shows `from`. Typing in the key field ends the auto key of a new palette node.
 */
import { isObject, str } from './store/json.ts';

export const KEY_SUGGESTED_EVENT = 'builder-v2:key-suggested';

/** The Livewire state path of the inspector's key field. */
export const KEY_STATE_PATH = 'inspectorData.key';

export interface KeySuggestion {
    readonly id: string | null;
    readonly key: string;
    /** The key the server saw when it made the suggestion. */
    readonly from: string;
}

export function parseKeySuggestion(detail: unknown): KeySuggestion | null {
    const value: unknown = Array.isArray(detail) ? detail[0] : detail;
    if (!isObject(value) || typeof value.key !== 'string' || typeof value.from !== 'string') {
        return null;
    }
    return { id: str(value.id), key: value.key, from: value.from };
}

/** True for the input bound to the key (`wire:model` with any modifiers). */
export function isKeyInput(target: EventTarget | null): target is HTMLInputElement {
    return (
        target instanceof HTMLInputElement &&
        Array.from(target.attributes).some(
            (attr) => attr.name.startsWith('wire:model') && attr.value === KEY_STATE_PATH,
        )
    );
}

export function keyInput(pane: ParentNode | null): HTMLInputElement | null {
    return Array.from(pane?.querySelectorAll('input') ?? []).find(isKeyInput) ?? null;
}

/** Writes a value the way typing does: Livewire's `wire:model` listens for `input`. */
export function fillInput(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}
