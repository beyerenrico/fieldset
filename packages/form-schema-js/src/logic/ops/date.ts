/** `date.diff` and `date.add` (spec §5.3), computed on parsed civil values in UTC arithmetic. */
import {
    addMonths,
    formatDate,
    formatDateTime,
    fromDayNumber,
    monthsBetween,
    parseDate,
    parseDateTime,
    toDayNumber,
    toEpochSeconds,
    type CivilDate,
    type CivilDateTime,
} from '../../date/calendar.ts';
import { instantSeconds } from '../../date/instant.ts';
import { toInteger } from '../../decimal/decimal.ts';
import { normalizeZero } from '../../json/types.ts';
import { evaluateArgs, type Operator } from '../operation.ts';

type Temporal =
    | { readonly kind: 'date'; readonly date: CivilDate }
    | { readonly kind: 'datetime'; readonly value: CivilDateTime };

const DATE_TIME_UNIT_SECONDS: Readonly<Record<string, number>> = {
    minutes: 60,
    hours: 3600,
    days: 86400,
};

function parseTemporal(value: unknown): Temporal | null {
    if (typeof value !== 'string') {
        return null;
    }
    const date = parseDate(value);
    if (date) {
        return { kind: 'date', date };
    }
    const dateTime = parseDateTime(value);
    return dateTime ? { kind: 'datetime', value: dateTime } : null;
}

function dateUnitSeconds(unit: unknown): number | null {
    return typeof unit === 'string' ? (DATE_TIME_UNIT_SECONDS[unit] ?? null) : null;
}

function diffDates(from: CivilDate, to: CivilDate, unit: unknown): number | null {
    const days = toDayNumber(to) - toDayNumber(from);
    switch (unit) {
        case 'days':
            return days;
        case 'weeks':
            return Math.trunc(days / 7);
        case 'months':
            return monthsBetween(from, to);
        case 'years':
            return Math.trunc(monthsBetween(from, to) / 12);
        default:
            return null;
    }
}

function diffDateTimes(from: CivilDateTime, to: CivilDateTime, unit: unknown): number | null {
    const unitSeconds = dateUnitSeconds(unit);
    if (unitSeconds === null) {
        return null;
    }
    return instantSeconds(to).minus(instantSeconds(from)).div(unitSeconds).trunc().toNumber();
}

const dateDiff: Operator = (args, data, evaluate) => {
    const [rawFrom, rawTo, unit] = evaluateArgs(args, data, evaluate);
    const [from, to] = [parseTemporal(rawFrom), parseTemporal(rawTo)];
    if (from?.kind === 'date' && to?.kind === 'date') {
        return nullableNumber(diffDates(from.date, to.date, unit));
    }
    if (from?.kind === 'datetime' && to?.kind === 'datetime') {
        return nullableNumber(diffDateTimes(from.value, to.value, unit));
    }
    return null;
};

function nullableNumber(value: number | null): number | null {
    return value === null ? null : normalizeZero(value);
}

function addToDate(date: CivilDate, amount: number, unit: unknown): string | null {
    switch (unit) {
        case 'days':
            return formatDate(fromDayNumber(toDayNumber(date) + amount));
        case 'weeks':
            return formatDate(fromDayNumber(toDayNumber(date) + amount * 7));
        case 'months':
            return formatDate(addMonths(date, amount));
        case 'years':
            return formatDate(addMonths(date, amount * 12));
        default:
            return null;
    }
}

function addToDateTime(value: CivilDateTime, amount: number, unit: unknown): string | null {
    const unitSeconds = dateUnitSeconds(unit);
    if (unitSeconds === null) {
        return null;
    }
    const epoch = toEpochSeconds(value) + amount * unitSeconds;
    return Number.isSafeInteger(epoch)
        ? formatDateTime(epoch, value.offsetMinutes, value.offset)
        : null;
}

const dateAdd: Operator = (args, data, evaluate) => {
    const [rawValue, rawAmount, unit] = evaluateArgs(args, data, evaluate);
    const value = parseTemporal(rawValue);
    const amount = toInteger(rawAmount);
    if (value === null || amount === null) {
        return null;
    }
    return value.kind === 'date'
        ? addToDate(value.date, amount, unit)
        : addToDateTime(value.value, amount, unit);
};

export const dateOperators: Readonly<Record<string, Operator>> = {
    'date.diff': dateDiff,
    'date.add': dateAdd,
};
