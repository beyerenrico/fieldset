import type { EvaluationContext } from '@form-schema-app/form-schema-js';

function pad(value: number): string {
    return String(Math.trunc(Math.abs(value))).padStart(2, '0');
}

/** RFC 3339 offset of the device for `date`, e.g. `+02:00`. */
function offsetOf(date: Date): string {
    const minutes = -date.getTimezoneOffset();
    const sign = minutes < 0 ? '-' : '+';
    return `${sign}${pad(minutes / 60)}:${pad(minutes % 60)}`;
}

/** Sample built-ins for the builder preview (device time zone, placeholder user and org). */
export function sampleContext(now: Date = new Date()): EvaluationContext {
    const today = `${String(now.getFullYear())}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    const time = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    return {
        today,
        now: `${today}T${time}${offsetOf(now)}`,
        user: { name: 'Max Mustermann', email: 'max.mustermann@example.com' },
        org: { name: 'Muster GmbH' },
        formVersion: 1,
    };
}
