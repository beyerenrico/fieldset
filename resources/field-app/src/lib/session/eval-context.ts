/** Evaluation context for `evaluate()`: today/now in the organization's time zone. */
import type { EvaluationContext } from '@form-schema-app/form-schema-js';
import type { Me, Organization } from '../api/types.ts';

const pad = (value: number, width = 2): string => String(Math.abs(value)).padStart(width, '0');

function zoneParts(date: Date, timeZone: string | undefined): Record<string, number> {
    const options: Intl.DateTimeFormatOptions = {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    };
    let format: Intl.DateTimeFormat;
    try {
        format = new Intl.DateTimeFormat('en-US', { ...options, timeZone });
    } catch {
        format = new Intl.DateTimeFormat('en-US', options);
    }
    return Object.fromEntries(
        format
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, Number(part.value)]),
    );
}

export interface ZonedNow {
    /** `YYYY-MM-DD`. */
    readonly today: string;
    /** RFC 3339 with the zone's offset, e.g. `2026-10-16T09:41:00+02:00`. */
    readonly now: string;
}

export function zonedNow(timeZone: string | null | undefined, date: Date = new Date()): ZonedNow {
    const p = zoneParts(date, timeZone ?? undefined);
    const year = p.year ?? 1970;
    const month = p.month ?? 1;
    const day = p.day ?? 1;
    const hour = (p.hour ?? 0) % 24;
    const minute = p.minute ?? 0;
    const second = p.second ?? 0;
    const asUtc = Date.UTC(year, month - 1, day, hour, minute, second);
    const offset = Math.round((asUtc - Math.floor(date.getTime() / 1000) * 1000) / 60000);
    const sign = offset < 0 ? '-' : '+';
    const today = `${pad(year, 4)}-${pad(month)}-${pad(day)}`;
    const zone = `${sign}${pad(Math.trunc(offset / 60))}:${pad(offset % 60)}`;
    return { today, now: `${today}T${pad(hour)}:${pad(minute)}:${pad(second)}${zone}` };
}

export function buildEvaluationContext(
    me: Me,
    organization: Organization | null,
    formVersion: number,
    date: Date = new Date(),
): EvaluationContext {
    const zone = organization?.timezone ?? me.user.timezone;
    return {
        ...zonedNow(zone, date),
        user: { name: me.user.name, email: me.user.email },
        org: { name: organization?.name ?? '' },
        formVersion,
    };
}
