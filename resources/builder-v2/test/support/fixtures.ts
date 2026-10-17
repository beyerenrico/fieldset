import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { JsonObject, JsonValue } from '@form-schema-app/form-schema-js';
import type { BuilderConfig } from '../../src/contract.ts';

/** The repository root (this file lives in resources/builder-v2/test/support). */
const repo = resolve(import.meta.dirname, '../../../..');

export function contractFixture(name: string): unknown {
    return JSON.parse(
        readFileSync(resolve(repo, `tests/Fixtures/builder-v2/contract/${name}.json`), 'utf8'),
    );
}

export function contractConfig(): BuilderConfig {
    return contractFixture('config') as BuilderConfig;
}

export function contractDefinition(): JsonObject {
    return deepFreeze(contractFixture('definition') as JsonObject);
}

export function templateFiles(): string[] {
    const dir = resolve(repo, 'spec/templates');
    return readdirSync(dir)
        .filter((file) => file.endsWith('.json') && file !== 'index.json')
        .map((file) => resolve(dir, file));
}

export function readJson(file: string): JsonObject {
    return JSON.parse(readFileSync(file, 'utf8')) as JsonObject;
}

/** Freezes a JSON value recursively, so any mutation throws in strict mode. */
export function deepFreeze<T extends JsonValue>(value: T): T {
    if (typeof value === 'object' && value !== null) {
        Object.values(value).forEach((child: JsonValue) => deepFreeze(child));
        Object.freeze(value);
    }
    return value;
}

/** Fixture ids, shortened for readable tests. */
export const ID = {
    pageStart: '0192f1c4-0000-7000-8000-00000000b001',
    pageItems: '0192f1c4-0000-7000-8000-00000000b002',
    customer: '0192f1c4-0000-7000-8000-00000000b011',
    details: '0192f1c4-0000-7000-8000-00000000b012',
    cols: '0192f1c4-0000-7000-8000-00000000b013',
    day: '0192f1c4-0000-7000-8000-00000000b014',
    items: '0192f1c4-0000-7000-8000-00000000b021',
    description: '0192f1c4-0000-7000-8000-00000000b022',
    qty: '0192f1c4-0000-7000-8000-00000000b023',
} as const;

let counter = 0;

/** Deterministic UUID-shaped ids for tests. */
export function testId(): string {
    counter += 1;
    return `0192f1c4-0000-7000-8000-${counter.toString(16).padStart(12, '0')}`;
}

/** A synthetic definition with `count` text elements spread over pages and sections. */
export function syntheticDefinition(count: number): JsonObject {
    const pages: JsonObject[] = [];
    let made = 0;
    for (let page = 0; made < count; page++) {
        const sections: JsonObject[] = [];
        for (let section = 0; section < 5 && made < count; section++) {
            const elements: JsonObject[] = [];
            for (let item = 0; item < 9 && made < count; item++) {
                made += 1;
                elements.push({
                    id: testId(),
                    type: 'text',
                    key: `field_${String(made)}`,
                    label: `Field ${String(made)}`,
                });
            }
            sections.push({
                id: testId(),
                type: 'section',
                key: `section_${String(page)}_${String(section)}`,
                label: 'Section',
                elements,
            });
        }
        pages.push({
            id: testId(),
            key: `page_${String(page)}`,
            title: `Page ${String(page)}`,
            elements: sections,
        });
    }
    return { specVersion: '1.0.0', key: 'synthetic', title: 'Synthetic', locale: 'de-DE', pages };
}
