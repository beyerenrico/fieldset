import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import { describe, expect, it } from 'vitest';
import * as sdk from '../src/index.ts';
import {
    evaluate,
    exportDataSchema,
    validateDefinition,
    type EvaluationContext,
} from '../src/index.ts';
import { el, form } from './helpers.ts';

const SPEC = join(dirname(fileURLToPath(import.meta.url)), '../../../spec');

describe('validateDefinition', () => {
    it('reports JSON pointers and readable messages', () => {
        const result = validateDefinition(form([el('text', 'a', { colour: 'red' })], { foo: 1 }));
        expect(result.valid).toBe(false);
        expect(result.errors).toContainEqual({
            path: '',
            message: 'must NOT have additional properties (foo)',
        });
        expect(result.errors).toContainEqual({
            path: '/pages/0/elements/0',
            message: 'must NOT have unevaluated properties (colour)',
        });
    });

    it('rejects prototype member names on elements with a readable message', () => {
        const definition = JSON.parse(
            JSON.stringify(form([el('text', 'a')])).replace('"label"', '"constructor":1,"label"'),
        ) as unknown;
        expect(validateDefinition(definition).errors).toEqual([
            {
                path: '/pages/0/elements/0',
                message: 'must NOT have unevaluated properties (constructor)',
            },
        ]);
    });

    it('accepts the conformance service report and caches the compiled validator', () => {
        const suite = JSON.parse(
            readFileSync(join(SPEC, 'conformance/evaluate/service-report.json'), 'utf8'),
        ) as {
            definition: unknown;
        };
        expect(validateDefinition(suite.definition)).toEqual({ valid: true, errors: [] });
        expect(validateDefinition(suite.definition).valid).toBe(true);
    });
});

describe('exportDataSchema', () => {
    const definition = form([
        el('text', 'name', { role: 'customer.name' }),
        el('email', 'mail'),
        el('phone', 'tel'),
        el('number', 'qty'),
        el('money', 'price', { currency: 'EUR' }),
        el('date', 'day'),
        el('time', 'at'),
        el('datetime', 'when'),
        el('select', 'kind', { options: [{ value: 'a', label: 'A' }] }),
        el('multiselect', 'tags', { options: [{ value: 'x', label: 'X' }] }),
        el('toggle', 'urgent'),
        el('rating', 'stars', { max: 3 }),
        el('photo', 'pics', { multiple: true }),
        el('file', 'doc'),
        el('signature', 'sig'),
        el('location', 'where'),
        el('calculated', 'total', { expression: { sum: [{ var: 'rows' }, 'line'] } }),
        el('calculated', 'label', { format: 'text', expression: 'x' }),
        el('hidden', 'ref'),
        el('heading', 'head', { text: 'Heading' }),
        el('repeater', 'rows', { elements: [el('number', 'line')] }),
    ]);
    const schema = exportDataSchema(definition);

    it('describes every data element and skips layout', () => {
        expect(schema['$schema']).toBe('https://json-schema.org/draft/2020-12/schema');
        const properties = schema['properties'] as Record<string, Record<string, unknown>>;
        expect(Object.keys(properties)).not.toContain('head');
        expect(properties['name']).toEqual({
            type: 'string',
            title: 'name',
            'x-role': 'customer.name',
        });
        expect(properties['tags']).toMatchObject({ type: 'array', uniqueItems: true });
        expect(properties['rows']).toMatchObject({ type: 'array', items: { type: 'object' } });
        expect(properties['stars']).toMatchObject({ type: 'integer', maximum: 3 });
    });

    it('is a valid JSON Schema that accepts evaluated data', () => {
        const ajv = new Ajv2020({ strict: false });
        addFormats(ajv);
        const validate = ajv.compile(schema);
        const result = evaluate(
            definition,
            {
                name: 'A',
                mail: 'a@b.de',
                tel: '+49 1',
                qty: '1.5',
                price: '2',
                day: '2026-10-16',
                at: '09:41',
                when: '2026-10-16T09:41:00Z',
                kind: 'a',
                tags: ['x'],
                urgent: true,
                stars: 2,
                pics: ['0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b6c'],
                doc: '0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b6c',
                sig: {
                    attachment: '0192f1c4-7a1e-7c3b-9a55-1d2e3f4a5b6c',
                    signerName: 'A',
                    signedAt: '2026-10-16T09:41:00Z',
                },
                where: { lat: 1, lng: 2, accuracy: 0, capturedAt: '2026-10-16T09:41:00Z' },
                ref: 3,
                rows: [{ line: '2' }],
            },
            {} satisfies EvaluationContext,
        );
        expect(result.errors).toEqual([]);
        expect(validate(result.data), JSON.stringify(validate.errors)).toBe(true);
    });

    it('handles definitions without a title', () => {
        expect(exportDataSchema({ pages: [] })).toEqual({
            $schema: 'https://json-schema.org/draft/2020-12/schema',
            type: 'object',
            properties: {},
            additionalProperties: false,
        });
    });
});

describe('public API surface', () => {
    it('exports the documented functions', () => {
        const names = [
            'decodeJson',
            'canonicalJson',
            'checksum',
            'validateDefinition',
            'evaluateLogic',
            'parseDefinition',
            'lint',
            'evaluate',
            'exportDataSchema',
        ] as const;
        for (const name of names) {
            expect(typeof sdk[name]).toBe('function');
        }
    });
});
