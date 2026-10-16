/** Exact decimal arithmetic for decimal strings (spec §5.1, §5.3). */
import DecimalJs from 'decimal.js';
import { isJsonInteger } from '../json/types.ts';

/** Independent decimal.js constructor: 1000 significant digits, half-up (away from zero). */
export const Decimal = DecimalJs.clone({
    precision: 1000,
    rounding: DecimalJs.ROUND_HALF_UP,
    toExpNeg: -9e15,
    toExpPos: 9e15,
});
export type Decimal = InstanceType<typeof Decimal>;

export const DECIMAL_PATTERN = /^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/;
const INTEGER_STRING = /^-?(0|[1-9][0-9]*)$/;

/** A decimal value together with its scale (number of fraction digits). */
export interface Dec {
    readonly value: Decimal;
    readonly scale: number;
}

/** Decimal strings have at most 60 digits in total (spec §5.1). */
export const MAX_DECIMAL_DIGITS = 60;

export function fractionDigits(decimalString: string): number {
    const dot = decimalString.indexOf('.');
    return dot === -1 ? 0 : decimalString.length - dot - 1;
}

export function integerDigits(decimalString: string): number {
    const unsigned = decimalString.startsWith('-') ? decimalString.slice(1) : decimalString;
    const dot = unsigned.indexOf('.');
    return dot === -1 ? unsigned.length : dot;
}

/** Matches the decimal grammar of §5.1 with at most 60 digits. */
export function isDecimalString(value: unknown): value is string {
    return (
        typeof value === 'string' &&
        DECIMAL_PATTERN.test(value) &&
        integerDigits(value) + fractionDigits(value) <= MAX_DECIMAL_DIGITS
    );
}

/** True when a money string stays within 60 digits once padded to `scale` fraction digits. */
export function fitsScale(decimalString: string, scale: number): boolean {
    return (
        integerDigits(decimalString) + Math.max(fractionDigits(decimalString), scale) <=
        MAX_DECIMAL_DIGITS
    );
}

/**
 * Coerces a decimal operand (§5.3): decimal strings of at most 60 digits and JSON integers are
 * accepted, everything else (floats, longer or invalid strings, booleans, arrays, objects, null)
 * yields `null`. Results of decimal operators may exceed 60 digits.
 */
export function toDec(value: unknown): Dec | null {
    if (isDecimalString(value)) {
        return { value: new Decimal(value), scale: fractionDigits(value) };
    }
    if (isJsonInteger(value)) {
        return { value: new Decimal(value), scale: 0 };
    }
    return null;
}

/** Integer operand: JSON integer or integer string (used for scales and date amounts). */
export function toInteger(value: unknown): number | null {
    if (isJsonInteger(value)) {
        return value;
    }
    if (typeof value === 'string' && INTEGER_STRING.test(value)) {
        const parsed = Number(value);
        return Number.isSafeInteger(parsed) ? parsed : null;
    }
    return null;
}

/**
 * Canonical decimal string: rounded half-up to `scale`, no exponent, `-0` normalized to `0`
 * while keeping the scale (e.g. `0.00`).
 */
export function formatDecimal(value: Decimal, scale: number): string {
    const rounded = value.toDecimalPlaces(scale, Decimal.ROUND_HALF_UP);
    return rounded.isZero() ? new Decimal(0).toFixed(scale) : rounded.toFixed(scale);
}

export function formatDec(dec: Dec): string {
    return formatDecimal(dec.value, dec.scale);
}

/**
 * Exact half-up division rounded to `scale` fraction digits. Returns `null` for a zero divisor.
 * Uses integer division plus remainder so no intermediate rounding can occur.
 */
export function divideHalfUp(dividend: Decimal, divisor: Decimal, scale: number): Decimal | null {
    if (divisor.isZero()) {
        return null;
    }
    const factor = new Decimal(10).pow(scale);
    const shifted = dividend.times(factor);
    const quotient = shifted.divToInt(divisor);
    const remainder = shifted.minus(quotient.times(divisor)).abs();
    const roundAway = remainder.times(2).gte(divisor.abs());
    const sign = shifted.isNegative() !== divisor.isNegative() ? -1 : 1;
    const adjusted = roundAway ? quotient.plus(sign) : quotient;
    return adjusted.div(factor);
}

/**
 * Numeric constraint value from a definition (§3.3): JSON numbers (shortest round-trip form)
 * or decimal strings. Returns `null` for anything else.
 */
export function constraintDecimal(value: unknown): Decimal | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return new Decimal(String(value));
    }
    return isDecimalString(value) ? new Decimal(value) : null;
}
