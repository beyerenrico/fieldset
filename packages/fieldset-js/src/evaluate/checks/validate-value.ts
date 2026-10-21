/** Per-element validation: required → type → constraints, first failure only (spec §5.6). */
import type { ElementNode } from '../../definition/model.ts';
import { integerProp, type JsonValue } from '../../json/types.ts';
import { constraintIssue } from './constraints.ts';
import { isEmpty, typeIssue, type Issue } from './type-checks.ts';

const REQUIRED: Issue = { code: 'required' };

/** Repeaters: `required` with no rows, then `minItems`/`maxItems` (also for an empty list). */
function repeaterIssue(
    node: ElementNode,
    value: JsonValue | undefined,
    required: boolean,
): Issue | null {
    const count = Array.isArray(value) ? value.length : 0;
    if (required && count === 0) {
        return REQUIRED;
    }
    const minItems = integerProp(node.raw, 'minItems');
    if (minItems !== undefined && count < minItems) {
        return { code: 'min_items', params: { limit: minItems } };
    }
    const maxItems = integerProp(node.raw, 'maxItems');
    return maxItems !== undefined && count > maxItems
        ? { code: 'max_items', params: { limit: maxItems } }
        : null;
}

/** The first failing check of one element value, or `null` when the value is valid. */
export function validateValue(
    node: ElementNode,
    value: JsonValue | undefined,
    required: boolean,
): Issue | null {
    if (node.type === 'repeater') {
        return repeaterIssue(node, value, required);
    }
    if (value === undefined || isEmpty(value)) {
        return required ? REQUIRED : null;
    }
    const typeProblem = typeIssue(node, value);
    if (typeProblem !== null) {
        return typeProblem;
    }
    if (node.type === 'checkbox' && required && value !== true) {
        return REQUIRED;
    }
    return constraintIssue(node, value);
}
