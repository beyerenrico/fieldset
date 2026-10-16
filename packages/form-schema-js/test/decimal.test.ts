import { describe, expect, it } from 'vitest';
import {
    constraintDecimal,
    Decimal,
    divideHalfUp,
    formatDecimal,
    fractionDigits,
    toDec,
    toInteger,
} from '../src/decimal/decimal.ts';

describe('toDec', () => {
    it('accepts decimal strings with their scale', () => {
        expect(toDec('12.340')?.scale).toBe(3);
        expect(toDec('-0')?.value.isZero()).toBe(true);
    });

    it('accepts JSON integers up to 2^53 with scale 0', () => {
        expect(toDec(2)?.scale).toBe(0);
        expect(toDec(2 ** 53)?.value.toFixed()).toBe('9007199254740992');
        expect(toDec(2 ** 54)).toBeNull();
    });

    it.each([0.5, '01', '1.', '.5', '+1', '1e3', ' 1', true, null, [], {}])(
        'rejects %j',
        (value) => {
            expect(toDec(value)).toBeNull();
        },
    );
});

describe('toInteger', () => {
    it('accepts JSON integers and integer strings', () => {
        expect(toInteger(-3)).toBe(-3);
        expect(toInteger('-12')).toBe(-12);
        expect(toInteger('0')).toBe(0);
    });

    it.each(['1.5', '01', '', 1.5, '99999999999999999999', null])('rejects %j', (value) => {
        expect(toInteger(value)).toBeNull();
    });
});

describe('formatDecimal', () => {
    it('rounds half away from zero and pads to the scale', () => {
        expect(formatDecimal(new Decimal('2.345'), 2)).toBe('2.35');
        expect(formatDecimal(new Decimal('-2.345'), 2)).toBe('-2.35');
        expect(formatDecimal(new Decimal('7'), 3)).toBe('7.000');
    });

    it('never emits an exponent or negative zero', () => {
        expect(formatDecimal(new Decimal('1e-30'), 2)).toBe('0.00');
        expect(formatDecimal(new Decimal('-0.001'), 2)).toBe('0.00');
        expect(formatDecimal(new Decimal('1e25'), 0)).toBe('10000000000000000000000000');
    });
});

describe('divideHalfUp', () => {
    it('divides exactly and rounds half up', () => {
        expect(divideHalfUp(new Decimal(2), new Decimal(3), 3)?.toFixed(3)).toBe('0.667');
        expect(divideHalfUp(new Decimal(-2), new Decimal(3), 3)?.toFixed(3)).toBe('-0.667');
        expect(divideHalfUp(new Decimal(1), new Decimal(-8), 2)?.toFixed(2)).toBe('-0.13');
        expect(divideHalfUp(new Decimal(-1), new Decimal(-8), 2)?.toFixed(2)).toBe('0.13');
    });

    it('returns null for a zero divisor', () => {
        expect(divideHalfUp(new Decimal(1), new Decimal(0), 2)).toBeNull();
    });
});

describe('constraint values', () => {
    it('reads JSON numbers via their shortest form and decimal strings', () => {
        expect(constraintDecimal(0.1)?.toFixed()).toBe('0.1');
        expect(constraintDecimal('2.50')?.toFixed(2)).toBe('2.50');
        expect(constraintDecimal('abc')).toBeNull();
        expect(constraintDecimal(Number.NaN)).toBeNull();
    });

    it('counts fraction digits', () => {
        expect(fractionDigits('1')).toBe(0);
        expect(fractionDigits('1.250')).toBe(3);
    });
});
