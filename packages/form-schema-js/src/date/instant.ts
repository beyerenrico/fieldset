/** Exact instants of date-times as decimal seconds (fractions kept without float rounding). */
import { Decimal } from '../decimal/decimal.ts';
import {
    parseDate,
    parseDateTime,
    parseTime,
    toDayNumber,
    toEpochSeconds,
    type CivilDateTime,
} from './calendar.ts';

export function instantSeconds(value: CivilDateTime): Decimal {
    const fraction = value.fraction === '' ? 0 : `0.${value.fraction}`;
    return new Decimal(toEpochSeconds(value)).plus(fraction);
}

/**
 * Orderable position of a `date`, `time` or `datetime` value string (day number, second of day,
 * or instant); `null` when the string is not a valid value of that type.
 */
export function temporalPosition(type: string, text: string): Decimal | null {
    if (type === 'date') {
        const date = parseDate(text);
        return date ? new Decimal(toDayNumber(date)) : null;
    }
    if (type === 'time') {
        const seconds = parseTime(text);
        return seconds === null ? null : new Decimal(seconds);
    }
    const dateTime = parseDateTime(text);
    return dateTime ? instantSeconds(dateTime) : null;
}
