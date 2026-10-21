/**
 * Calendar parsing and arithmetic without host `Date` or time-zone dependencies.
 * Dates are proleptic Gregorian; day numbers count days since 1970-01-01.
 */

export interface CivilDate {
    readonly year: number;
    readonly month: number;
    readonly day: number;
}

export interface CivilDateTime extends CivilDate {
    readonly hour: number;
    readonly minute: number;
    readonly second: number;
    /** Fraction digits after the decimal point, without the dot (may be empty). */
    readonly fraction: string;
    /** Offset from UTC in minutes. */
    readonly offsetMinutes: number;
    /** Offset as written (`Z` or `±HH:MM`). */
    readonly offset: string;
}

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const TIME = /^(\d{2}):(\d{2})(?::(\d{2}))?$/;
const DATE_TIME =
    /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/;

export function isLeapYear(year: number): boolean {
    return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

export function daysInMonth(year: number, month: number): number {
    const lengths = [31, isLeapYear(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    return lengths[month - 1] ?? 0;
}

function isRealDate(year: number, month: number, day: number): boolean {
    return month >= 1 && month <= 12 && day >= 1 && day <= daysInMonth(year, month);
}

export function parseDate(text: string): CivilDate | null {
    const match = DATE.exec(text);
    if (!match) {
        return null;
    }
    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    return isRealDate(year, month, day) ? { year, month, day } : null;
}

/** Seconds since midnight for `HH:MM` or `HH:MM:SS` (24 h), or `null`. */
export function parseTime(text: string): number | null {
    const match = TIME.exec(text);
    if (!match) {
        return null;
    }
    const [hour, minute, second] = [Number(match[1]), Number(match[2]), Number(match[3] ?? '0')];
    return hour <= 23 && minute <= 59 && second <= 59 ? hour * 3600 + minute * 60 + second : null;
}

function parseOffset(offset: string): number | null {
    if (offset === 'Z') {
        return 0;
    }
    const hours = Number(offset.slice(1, 3));
    const minutes = Number(offset.slice(4, 6));
    if (hours > 23 || minutes > 59) {
        return null;
    }
    const sign = offset.startsWith('-') ? -1 : 1;
    return sign * (hours * 60 + minutes);
}

/** RFC 3339 date-time with mandatory offset: `YYYY-MM-DDTHH:MM:SS(.fraction)?(Z|±HH:MM)`. */
export function parseDateTime(text: string): CivilDateTime | null {
    const match = DATE_TIME.exec(text);
    if (!match) {
        return null;
    }
    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const [hour, minute, second] = [Number(match[4]), Number(match[5]), Number(match[6])];
    const offset = match[8] ?? 'Z';
    const offsetMinutes = parseOffset(offset);
    const validClock = hour <= 23 && minute <= 59 && second <= 59;
    if (!isRealDate(year, month, day) || !validClock || offsetMinutes === null) {
        return null;
    }
    const fraction = match[7] ?? '';
    return { year, month, day, hour, minute, second, fraction, offsetMinutes, offset };
}

/** Days since 1970-01-01 (Howard Hinnant's days_from_civil). */
export function toDayNumber(date: CivilDate): number {
    const year = date.month <= 2 ? date.year - 1 : date.year;
    const era = Math.floor(year / 400);
    const yearOfEra = year - era * 400;
    const monthIndex = date.month > 2 ? date.month - 3 : date.month + 9;
    const dayOfYear = Math.floor((153 * monthIndex + 2) / 5) + date.day - 1;
    const dayOfEra =
        yearOfEra * 365 + Math.floor(yearOfEra / 4) - Math.floor(yearOfEra / 100) + dayOfYear;
    return era * 146097 + dayOfEra - 719468;
}

/** Inverse of `toDayNumber` (civil_from_days). */
export function fromDayNumber(dayNumber: number): CivilDate {
    const shifted = dayNumber + 719468;
    const era = Math.floor(shifted / 146097);
    const dayOfEra = shifted - era * 146097;
    const yearOfEra = Math.floor(
        (dayOfEra -
            Math.floor(dayOfEra / 1460) +
            Math.floor(dayOfEra / 36524) -
            Math.floor(dayOfEra / 146096)) /
            365,
    );
    const dayOfYear =
        dayOfEra - (365 * yearOfEra + Math.floor(yearOfEra / 4) - Math.floor(yearOfEra / 100));
    const monthIndex = Math.floor((5 * dayOfYear + 2) / 153);
    const day = dayOfYear - Math.floor((153 * monthIndex + 2) / 5) + 1;
    const month = monthIndex < 10 ? monthIndex + 3 : monthIndex - 9;
    const year = yearOfEra + era * 400 + (month <= 2 ? 1 : 0);
    return { year, month, day };
}

/** Whole seconds since the Unix epoch (UTC) of a date-time, ignoring its fraction. */
export function toEpochSeconds(value: CivilDateTime): number {
    const local = toDayNumber(value) * 86400 + value.hour * 3600 + value.minute * 60 + value.second;
    return local - value.offsetMinutes * 60;
}

/** Month difference between dates per §5.3 (`a ≤ b` rule mirrored for `a > b`). */
export function monthsBetween(from: CivilDate, to: CivilDate): number {
    if (toDayNumber(from) > toDayNumber(to)) {
        return -monthsBetween(to, from);
    }
    const months = (to.year - from.year) * 12 + (to.month - from.month);
    return to.day < from.day ? months - 1 : months;
}

/** Adds months, clamping the day to the end of the target month. */
export function addMonths(date: CivilDate, months: number): CivilDate {
    const monthIndex = date.year * 12 + (date.month - 1) + months;
    const year = Math.floor(monthIndex / 12);
    const month = monthIndex - year * 12 + 1;
    return { year, month, day: Math.min(date.day, daysInMonth(year, month)) };
}

function pad(value: number, width: number): string {
    return String(value).padStart(width, '0');
}

/** `YYYY-MM-DD`, or `null` when the year is outside 0000–9999. */
export function formatDate(date: CivilDate): string | null {
    if (date.year < 0 || date.year > 9999) {
        return null;
    }
    return `${pad(date.year, 4)}-${pad(date.month, 2)}-${pad(date.day, 2)}`;
}

/** `YYYY-MM-DDTHH:MM:SS` + offset for an epoch second rendered in the given offset. */
export function formatDateTime(
    epochSeconds: number,
    offsetMinutes: number,
    offset: string,
): string | null {
    const local = epochSeconds + offsetMinutes * 60;
    const dayNumber = Math.floor(local / 86400);
    const secondOfDay = local - dayNumber * 86400;
    const date = formatDate(fromDayNumber(dayNumber));
    if (date === null) {
        return null;
    }
    const clock = [
        Math.floor(secondOfDay / 3600),
        Math.floor((secondOfDay % 3600) / 60),
        secondOfDay % 60,
    ];
    return `${date}T${clock.map((part) => pad(part, 2)).join(':')}${offset}`;
}
