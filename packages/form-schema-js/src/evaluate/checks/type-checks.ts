/** Type checks of §5.6 (codes `type_mismatch` … `invalid_location`) for non-empty values. */
import { parseDate, parseDateTime, parseTime } from '../../date/calendar.ts';
import type { ElementNode } from '../../definition/model.ts';
import { fitsScale, fractionDigits, isDecimalString } from '../../decimal/decimal.ts';
import {
    arrayProp,
    getOwn,
    integerProp,
    isJsonInteger,
    isPlainObject,
    stringProp,
    type JsonValue,
} from '../../json/types.ts';
import type { ErrorCode, ErrorParams } from '../types.ts';
import { isAttachmentId, isLocation, isSignature } from './shapes.ts';

export interface Issue {
    readonly code: ErrorCode;
    readonly params?: ErrorParams;
}

type TypeCheck = (node: ElementNode, value: JsonValue) => Issue | null;

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/u;
/** Email addresses longer than this many code points are invalid (§5.6). */
const MAX_EMAIL_LENGTH = 254;
const PHONE = /^\+?[0-9 ()./-]{3,32}$/;
const MISMATCH: Issue = { code: 'type_mismatch' };

/** Integer property within `[0, max]`, otherwise `fallback` (definitions may be unvalidated). */
export function boundedInteger(
    node: ElementNode,
    key: string,
    max: number,
    fallback: number,
): number {
    const value = integerProp(node.raw, key);
    return value !== undefined && value >= 0 && value <= max ? value : fallback;
}

/** Money scale 0–4 (default 2). */
export function moneyScale(node: ElementNode): number {
    return boundedInteger(node, 'scale', 4, 2);
}

function issue(code: ErrorCode): Issue {
    return { code };
}

function stringCheck(valid: (text: string) => boolean, code: ErrorCode): TypeCheck {
    return (_node, value) => {
        if (typeof value !== 'string') {
            return MISMATCH;
        }
        return valid(value) ? null : issue(code);
    };
}

function decimalCheck(node: ElementNode, value: JsonValue): Issue | null {
    if (typeof value === 'number') {
        return issue('decimal_string_required');
    }
    if (typeof value !== 'string') {
        return MISMATCH;
    }
    const scale = moneyScale(node);
    const money = node.type === 'money';
    if (!isDecimalString(value) || (money && !fitsScale(value, scale))) {
        return issue('invalid_number');
    }
    return money && fractionDigits(value) > scale
        ? { code: 'scale_exceeded', params: { scale } }
        : null;
}

export function optionValues(node: ElementNode): ReadonlySet<string> {
    const options = arrayProp(node.raw, 'options') ?? [];
    return new Set(
        options.flatMap((option) => {
            const value = isPlainObject(option) ? stringProp(option, 'value') : undefined;
            return value === undefined ? [] : [value];
        }),
    );
}

const singleOption: TypeCheck = (node, value) => {
    if (typeof value !== 'string') {
        return MISMATCH;
    }
    return optionValues(node).has(value) ? null : issue('invalid_option');
};

const multipleOptions: TypeCheck = (node, value) => {
    if (!Array.isArray(value)) {
        return MISMATCH;
    }
    const allowed = optionValues(node);
    const items = value as readonly JsonValue[];
    const valid = items.every((item) => typeof item === 'string' && allowed.has(item));
    return valid && new Set(items).size === items.length ? null : issue('invalid_option');
};

const booleanCheck: TypeCheck = (_node, value) => (typeof value === 'boolean' ? null : MISMATCH);

const attachments: TypeCheck = (node, value) => {
    const multiple = getOwn(node.raw, 'multiple') === true;
    const valid = multiple
        ? Array.isArray(value) && (value as readonly JsonValue[]).every(isAttachmentId)
        : isAttachmentId(value);
    return valid ? null : issue('invalid_attachment');
};

function objectCheck(valid: (value: JsonValue) => boolean, code: ErrorCode): TypeCheck {
    return (_node, value) => {
        if (!isPlainObject(value)) {
            return MISMATCH;
        }
        return valid(value) ? null : issue(code);
    };
}

const hiddenCheck: TypeCheck = (_node, value) =>
    typeof value === 'string' || typeof value === 'boolean' || isJsonInteger(value)
        ? null
        : MISMATCH;

const TYPE_CHECKS: Readonly<Record<string, TypeCheck>> = {
    text: stringCheck(() => true, 'type_mismatch'),
    textarea: stringCheck(() => true, 'type_mismatch'),
    email: stringCheck(
        (text) => Array.from(text).length <= MAX_EMAIL_LENGTH && EMAIL.test(text),
        'invalid_email',
    ),
    phone: stringCheck((text) => PHONE.test(text), 'invalid_phone'),
    date: stringCheck((text) => parseDate(text) !== null, 'invalid_date'),
    time: stringCheck((text) => parseTime(text) !== null, 'invalid_time'),
    datetime: stringCheck((text) => parseDateTime(text) !== null, 'invalid_datetime'),
    number: decimalCheck,
    money: decimalCheck,
    select: singleOption,
    radio: singleOption,
    multiselect: multipleOptions,
    checkbox: booleanCheck,
    toggle: booleanCheck,
    photo: attachments,
    file: attachments,
    signature: objectCheck(isSignature, 'invalid_signature'),
    location: objectCheck(isLocation, 'invalid_location'),
    hidden: hiddenCheck,
};

/**
 * First failing type check of a non-empty value, or `null`. `rating`, `calculated` and
 * `repeater` have no type check here (rating ranges are `out_of_range`, a constraint).
 */
export function typeIssue(node: ElementNode, value: JsonValue): Issue | null {
    const check = TYPE_CHECKS[node.type];
    return check === undefined ? null : check(node, value);
}

/** Empty: missing, `null`, `""` or `[]`. */
export function isEmpty(value: JsonValue | undefined): boolean {
    return (
        value === undefined ||
        value === null ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}
