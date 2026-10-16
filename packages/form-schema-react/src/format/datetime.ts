/** Conversions between spec date-time strings and native `datetime-local` input values. */

function pad(value: number, length = 2): string {
    return String(Math.trunc(Math.abs(value))).padStart(length, '0');
}

function localDate(date: Date): string {
    return `${pad(date.getFullYear(), 4)}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function offsetOf(date: Date): string {
    const minutes = -date.getTimezoneOffset();
    const sign = minutes < 0 ? '-' : '+';
    return `${sign}${pad(Math.abs(minutes) / 60)}:${pad(Math.abs(minutes) % 60)}`;
}

/** RFC 3339 with the device's offset for that instant, e.g. `2026-10-16T09:41:00+02:00`. */
export function toRfc3339(date: Date): string {
    const time = `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    return `${localDate(date)}T${time}${offsetOf(date)}`;
}

export function nowRfc3339(): string {
    return toRfc3339(new Date());
}

/** Stored date-time → device-local wall clock for `<input type="datetime-local">`. */
export function toLocalInputValue(value: unknown): string {
    if (typeof value !== 'string' || value === '') {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const seconds = date.getSeconds() === 0 ? '' : `:${pad(date.getSeconds())}`;
    return `${localDate(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}${seconds}`;
}

/** `YYYY-MM-DDTHH:MM[:SS]` (device-local) → RFC 3339 with the device offset; `null` if invalid. */
export function fromLocalInputValue(local: string): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/.exec(local);
    if (match === null) {
        return null;
    }
    const [, year, month, day, hours, minutes, seconds] = match.map(Number);
    const date = new Date(
        year ?? 0,
        (month ?? 1) - 1,
        day ?? 1,
        hours ?? 0,
        minutes ?? 0,
        Number.isNaN(seconds) ? 0 : (seconds ?? 0),
    );
    return Number.isNaN(date.getTime()) ? null : toRfc3339(date);
}
