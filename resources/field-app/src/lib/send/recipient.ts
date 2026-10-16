/** "Send to customer" recipient (§9.2): defaults from the semantic roles, client-side checks. */
import {
    parseDefinition,
    type ElementNode,
    type FormDefinition,
    type JsonObject,
} from '@form-schema-app/form-schema-js';
import type { SendInput } from '../api/endpoints.ts';

export interface RecipientDraft {
    readonly email: string;
    readonly name: string;
}

const EMPTY: RecipientDraft = { email: '', name: '' };
/** RFC 5321 path limit; the server validates authoritatively. */
const MAX_EMAIL_LENGTH = 254;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)+$/;

/** Submitted string value of the first root-scope element with `role`, trimmed. */
function valueForRole(
    keys: ReadonlyMap<string, ElementNode>,
    data: JsonObject,
    role: string,
): string {
    for (const [key, node] of keys) {
        const value = data[key];
        if (node.element.role === role && typeof value === 'string') {
            return value.trim();
        }
    }
    return '';
}

/** Prefill from the elements with roles `customer.email` / `customer.name` (pinned version). */
export function customerDefaults(
    definition: FormDefinition | undefined,
    data: JsonObject,
): RecipientDraft {
    if (definition === undefined) {
        return EMPTY;
    }
    try {
        const keys = parseDefinition(definition).root.dataKeys;
        return {
            email: valueForRole(keys, data, 'customer.email'),
            name: valueForRole(keys, data, 'customer.name'),
        };
    } catch {
        return EMPTY;
    }
}

export function isValidEmail(value: string): boolean {
    return value.length <= MAX_EMAIL_LENGTH && EMAIL_PATTERN.test(value);
}

/**
 * Request body: trimmed values, the name always as typed. The dialog is prefilled from the roles,
 * so the user sees what is used; a cleared name is sent as `""` ("no name", no role fallback).
 */
export function toSendInput(draft: RecipientDraft): SendInput {
    return { email: draft.email.trim(), name: draft.name.trim() };
}
