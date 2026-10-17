import { canonicalJson, type JsonObject } from '@form-schema-app/form-schema-js';
import { describe, expect, it } from 'vitest';
import { normalizeIds, uuidv7, withFreshIds } from '../src/store/ids.ts';
import { contractDefinition, testId } from './support/fixtures.ts';

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('ids', () => {
    it('creates time-ordered UUIDv7 values', () => {
        const id = uuidv7(0x0192f1c4a1b2, (bytes) => bytes.fill(0xff));
        expect(id).toBe('0192f1c4-a1b2-7fff-bfff-ffffffffffff');
        expect(uuidv7()).toMatch(UUID_V7);
        expect(uuidv7(1000) < uuidv7(2000)).toBe(true);
    });

    it('gives a palette subtree fresh ids', () => {
        const node: JsonObject = {
            type: 'columns',
            key: 'c',
            columns: [
                { elements: [{ type: 'text', key: 't', label: 'T' }] },
                { id: 'old', elements: [] },
            ],
        };
        const fresh = withFreshIds(node, testId);
        expect(fresh.id).toMatch(UUID_V7);
        expect(JSON.stringify(fresh)).not.toContain('"old"');
        expect(JSON.stringify(fresh).match(/"id"/g)).toHaveLength(3);
        expect(node).not.toHaveProperty('id');
    });

    it('keeps a valid definition untouched and repairs missing or duplicate ids', () => {
        const valid = contractDefinition();
        expect(normalizeIds(valid)).toBe(valid);
        const broken: JsonObject = {
            pages: [
                {
                    key: 'a',
                    title: 'A',
                    elements: [
                        { id: 'x', type: 'text', key: 'one' },
                        { id: 'x', type: 'text', key: 'two' },
                    ],
                },
                {
                    id: 'p',
                    key: 'b',
                    title: 'B',
                    elements: [
                        {
                            id: 'c',
                            type: 'columns',
                            key: 'c',
                            columns: [{ elements: [{ type: 'text', key: 'three' }] }],
                        },
                    ],
                },
            ],
        };
        const repaired = normalizeIds(broken, testId);
        const ids = JSON.stringify(repaired).match(/"id":"[^"]+"/g) ?? [];
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids).toHaveLength(6);
        expect(canonicalJson(normalizeIds(repaired))).toBe(canonicalJson(repaired));
        expect(normalizeIds({ key: 'no pages' })).toEqual({ key: 'no pages' });
    });
});
