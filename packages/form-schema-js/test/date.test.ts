import { describe, expect, it } from 'vitest';
import {
    addMonths,
    formatDate,
    formatDateTime,
    fromDayNumber,
    monthsBetween,
    parseDate,
    parseDateTime,
    parseTime,
    toDayNumber,
} from '../src/date/calendar.ts';
import { temporalPosition } from '../src/date/instant.ts';
import { evaluateLogic } from '../src/index.ts';

describe('calendar parsing', () => {
    it('accepts real dates only', () => {
        expect(parseDate('2028-02-29')).toEqual({ year: 2028, month: 2, day: 29 });
        expect(parseDate('2100-02-29')).toBeNull();
        expect(parseDate('2000-02-29')).not.toBeNull();
        expect(parseDate('2026-13-01')).toBeNull();
        expect(parseDate('2026-1-01')).toBeNull();
    });

    it('parses times with optional seconds', () => {
        expect(parseTime('23:59:59')).toBe(86399);
        expect(parseTime('00:00')).toBe(0);
        expect(parseTime('24:00')).toBeNull();
        expect(parseTime('12:60')).toBeNull();
        expect(parseTime('12:00:60')).toBeNull();
    });

    it('parses RFC 3339 date-times with mandatory offset', () => {
        expect(parseDateTime('2026-10-16T09:41:00.250-05:30')).toMatchObject({
            fraction: '250',
            offsetMinutes: -330,
            offset: '-05:30',
        });
        expect(parseDateTime('2026-10-16T09:41:00')).toBeNull();
        expect(parseDateTime('2026-10-16T09:41:00+24:00')).toBeNull();
        expect(parseDateTime('2026-10-16T09:41:00+01:60')).toBeNull();
        expect(parseDateTime('2026-02-30T09:41:00Z')).toBeNull();
        expect(parseDateTime('2026-10-16T24:00:00Z')).toBeNull();
        expect(parseDateTime('2026-10-16t09:41:00z')).toBeNull();
    });
});

describe('day numbers', () => {
    it('round-trips across centuries, including negative day numbers', () => {
        for (let day = -800000; day <= 800000; day += 997) {
            expect(toDayNumber(fromDayNumber(day))).toBe(day);
        }
        expect(toDayNumber({ year: 1970, month: 1, day: 1 })).toBe(0);
        expect(fromDayNumber(-1)).toEqual({ year: 1969, month: 12, day: 31 });
    });

    it('computes month differences per spec', () => {
        const d = (text: string) => parseDate(text) ?? { year: 0, month: 0, day: 0 };
        expect(monthsBetween(d('2026-01-31'), d('2026-03-01'))).toBe(1);
        expect(monthsBetween(d('2026-03-01'), d('2026-01-31'))).toBe(-1);
        expect(monthsBetween(d('2026-01-15'), d('2026-01-15'))).toBe(0);
    });

    it('adds months with clamping, also backwards', () => {
        expect(addMonths({ year: 2026, month: 3, day: 31 }, -1)).toEqual({
            year: 2026,
            month: 2,
            day: 28,
        });
        expect(addMonths({ year: 2026, month: 1, day: 15 }, -13)).toEqual({
            year: 2024,
            month: 12,
            day: 15,
        });
    });

    it('formats only years 0000-9999', () => {
        expect(formatDate({ year: 10000, month: 1, day: 1 })).toBeNull();
        expect(formatDate({ year: -1, month: 1, day: 1 })).toBeNull();
        expect(formatDate({ year: 5, month: 3, day: 7 })).toBe('0005-03-07');
        expect(formatDateTime(-1, 0, 'Z')).toBe('1969-12-31T23:59:59Z');
        expect(formatDateTime(1e15, 0, 'Z')).toBeNull();
    });

    it('orders temporal values', () => {
        expect(temporalPosition('time', '09:41')?.toNumber()).toBe(34860);
        expect(temporalPosition('time', 'x')).toBeNull();
        expect(temporalPosition('date', 'x')).toBeNull();
        expect(temporalPosition('datetime', '1970-01-01T01:00:00.5+01:00')?.toNumber()).toBe(0.5);
        expect(temporalPosition('datetime', 'x')).toBeNull();
    });
});

describe('date operators', () => {
    const run = (logic: unknown) => evaluateLogic(logic, {});

    it('diffs date-times exactly, truncating toward zero', () => {
        expect(
            run({ 'date.diff': ['2026-10-16T00:00:00.0005Z', '2026-10-16T00:01:00Z', 'minutes'] }),
        ).toBe(0);
        expect(
            run({ 'date.diff': ['2026-10-16T00:01:00Z', '2026-10-16T00:00:00.5Z', 'minutes'] }),
        ).toBe(0);
        expect(
            run({ 'date.diff': ['2026-10-15T23:00:00-02:00', '2026-10-17T01:00:00Z', 'days'] }),
        ).toBe(1);
        expect(
            run({ 'date.diff': ['2026-10-16T00:00:00Z', '2026-10-16T00:00:00Z', 'weeks'] }),
        ).toBeNull();
    });

    it('diffs dates in all units', () => {
        expect(run({ 'date.diff': ['2026-10-16', '2026-10-01', 'weeks'] })).toBe(-2);
        expect(run({ 'date.diff': ['2026-10-16', '2025-10-17', 'years'] })).toBe(0);
        expect(run({ 'date.diff': ['2026-10-16', '2026-10-17', 'fortnights'] })).toBeNull();
        expect(run({ 'date.diff': [5, '2026-10-17', 'days'] })).toBeNull();
    });

    it('adds to dates and date-times', () => {
        expect(run({ 'date.add': ['2026-10-16', -1, 'years'] })).toBe('2025-10-16');
        expect(run({ 'date.add': ['2026-10-16', 1, 'hours'] })).toBeNull();
        expect(run({ 'date.add': ['2026-03-29T01:30:00.999+01:00', 1, 'days'] })).toBe(
            '2026-03-30T01:30:00+01:00',
        );
        expect(run({ 'date.add': ['2026-10-16T00:30:00-03:00', -1, 'hours'] })).toBe(
            '2026-10-15T23:30:00-03:00',
        );
        expect(run({ 'date.add': ['2026-10-16T00:30:00Z', 1, 'months'] })).toBeNull();
        expect(run({ 'date.add': ['9999-12-31', 1, 'days'] })).toBeNull();
        expect(run({ 'date.add': ['2026-10-16T00:30:00Z', 2 ** 53, 'days'] })).toBeNull();
    });
});
