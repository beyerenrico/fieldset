/** Builders for compact test definitions. */
import type { EvaluationContext } from '../src/index.ts';

let sequence = 0;

export function uuid(): string {
    sequence += 1;
    return `00000000-0000-4000-8000-${String(sequence).padStart(12, '0')}`;
}

export type Raw = Record<string, unknown>;

export function el(type: string, key: string, props: Raw = {}): Raw {
    return { id: uuid(), type, key, label: key, ...props };
}

export function page(key: string, elements: readonly unknown[], props: Raw = {}): Raw {
    return { key, title: key.toUpperCase(), elements, ...props };
}

export function form(elements: readonly unknown[], extra: Raw = {}): Raw {
    return {
        specVersion: '1.0.0',
        key: 'test_form',
        title: 'Test',
        locale: 'de-DE',
        pages: [page('main', elements)],
        ...extra,
    };
}

export const CONTEXT: EvaluationContext = {
    today: '2026-10-16',
    now: '2026-10-16T09:41:00+02:00',
    user: { name: 'Willi Worker', email: 'worker@demo.test' },
    org: { name: 'Demo GmbH' },
    formVersion: 3,
};

export function deepFreeze<T>(value: T): T {
    if (typeof value === 'object' && value !== null) {
        for (const member of Object.values(value as object) as unknown[]) {
            deepFreeze(member);
        }
        Object.freeze(value);
    }
    return value;
}
