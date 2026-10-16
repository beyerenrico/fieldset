/** Constraint checks of §3.3/§5.6 for values that passed their type check. */
import { temporalPosition } from '../../date/instant.ts';
import type { ElementNode } from '../../definition/model.ts';
import { matchesPattern } from '../../definition/pattern.ts';
import { constraintDecimal, Decimal } from '../../decimal/decimal.ts';
import {
    getOwn,
    integerProp,
    isJsonInteger,
    numberProp,
    objectProp,
    stringProp,
    type JsonValue,
    type RawObject,
} from '../../json/types.ts';
import type { Issue } from './type-checks.ts';

type Constraint = (node: ElementNode, value: JsonValue, validation: RawObject) => Issue | null;

const PATTERN_TYPES: ReadonlySet<string> = new Set(['text', 'textarea', 'phone']);

/** Values reaching string constraints passed their type check, so they are strings. */
function asText(value: JsonValue): string {
    return typeof value === 'string' ? value : '';
}

function limit(
    code: 'min' | 'max' | 'min_length' | 'max_length' | 'min_items' | 'max_items',
    bound: unknown,
): Issue {
    return { code, params: { limit: bound as JsonValue } };
}

const textual: Constraint = (node, value, validation) => {
    const text = asText(value);
    const length = Array.from(text).length;
    const minLength = integerProp(validation, 'minLength');
    if (minLength !== undefined && length < minLength) {
        return limit('min_length', minLength);
    }
    const maxLength = integerProp(validation, 'maxLength');
    if (maxLength !== undefined && length > maxLength) {
        return limit('max_length', maxLength);
    }
    const pattern = PATTERN_TYPES.has(node.type) ? stringProp(validation, 'pattern') : undefined;
    return pattern === undefined || matchesPattern(pattern, text) ? null : { code: 'pattern' };
};

/** `min`/`max` comparison shared by numeric and temporal values. */
function bounds(
    position: Decimal,
    validation: RawObject,
    read: (bound: unknown) => Decimal | null,
): Issue | null {
    const min = getOwn(validation, 'min');
    const minPosition = read(min);
    if (minPosition !== null && position.lt(minPosition)) {
        return limit('min', min);
    }
    const max = getOwn(validation, 'max');
    const maxPosition = read(max);
    return maxPosition !== null && position.gt(maxPosition) ? limit('max', max) : null;
}

const numeric: Constraint = (_node, value, validation) => {
    const number = new Decimal(asText(value));
    const bound = bounds(number, validation, constraintDecimal);
    if (bound !== null) {
        return bound;
    }
    const step = getOwn(validation, 'step');
    const stepValue = constraintDecimal(step);
    if (stepValue === null || !stepValue.gt(0)) {
        return null;
    }
    const base = constraintDecimal(getOwn(validation, 'min')) ?? new Decimal(0);
    return number.minus(base).mod(stepValue).isZero()
        ? null
        : { code: 'step', params: { step: step as JsonValue } };
};

const temporal: Constraint = (node, value, validation) => {
    const position = temporalPosition(node.type, asText(value));
    const read = (bound: unknown): Decimal | null =>
        typeof bound === 'string' ? temporalPosition(node.type, bound) : null;
    return position === null ? null : bounds(position, validation, read);
};

const selectionCount: Constraint = (_node, value, validation) => {
    const count = Array.isArray(value) ? value.length : 0;
    const min = numberProp(validation, 'min');
    if (min !== undefined && count < min) {
        return limit('min', min);
    }
    const max = numberProp(validation, 'max');
    return max !== undefined && count > max ? limit('max', max) : null;
};

const fileCount: Constraint = (node, value, validation) => {
    const maxFiles = integerProp(validation, 'maxFiles');
    const multiple = getOwn(node.raw, 'multiple') === true;
    return multiple && maxFiles !== undefined && Array.isArray(value) && value.length > maxFiles
        ? limit('max_items', maxFiles)
        : null;
};

const rating: Constraint = (node, value) => {
    const max = integerProp(node.raw, 'max') ?? 5;
    return isJsonInteger(value) && value >= 1 && value <= max ? null : { code: 'out_of_range' };
};

const CONSTRAINTS: Readonly<Record<string, Constraint>> = {
    text: textual,
    textarea: textual,
    email: textual,
    phone: textual,
    number: numeric,
    money: numeric,
    date: temporal,
    time: temporal,
    datetime: temporal,
    multiselect: selectionCount,
    photo: fileCount,
    file: fileCount,
    rating,
};

/** First failing constraint of a type-valid, non-empty value, or `null`. */
export function constraintIssue(node: ElementNode, value: JsonValue): Issue | null {
    const check = CONSTRAINTS[node.type];
    return check === undefined
        ? null
        : check(node, value, objectProp(node.raw, 'validation') ?? {});
}
