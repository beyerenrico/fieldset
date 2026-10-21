/** Runs every case of spec/conformance (README there defines the assertions). */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
    canonicalJson,
    checksum,
    evaluate,
    evaluateLogic,
    lint,
    validateDefinition,
    type EvaluationContext,
    type EvaluationResult,
    type JsonValue,
    type LintFinding,
    type ValidationError,
} from '../src/index.ts';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../../../spec/conformance');

interface Suite<T> {
    readonly description: string;
    readonly tests: readonly (T & { readonly description: string })[];
}

function jsonFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry);
        if (statSync(path).isDirectory()) {
            return jsonFiles(path);
        }
        return entry.endsWith('.json') ? [path] : [];
    });
}

function suites<T>(folder: string): [string, Suite<T>][] {
    return jsonFiles(join(ROOT, folder)).map((path) => [
        relative(ROOT, path),
        JSON.parse(readFileSync(path, 'utf8')) as Suite<T>,
    ]);
}

const KNOWN_FOLDERS = ['checksum', 'evaluate', 'lint', 'logic', 'meta-schema'];

it('discovers only known conformance folders', () => {
    const folders = readdirSync(ROOT).filter((entry) => statSync(join(ROOT, entry)).isDirectory());
    expect(folders.sort()).toEqual(KNOWN_FOLDERS);
});

describe.each(suites<{ logic: unknown; data: unknown; expected: unknown }>('logic'))(
    '%s',
    (_file, suite) => {
        it.each(suite.tests)('$description', ({ logic, data, expected }) => {
            expect(evaluateLogic(logic, data)).toEqual(expected);
        });
    },
);

describe.each(suites<{ value: unknown; canonical: string; sha256: string }>('checksum'))(
    '%s',
    (_file, suite) => {
        it.each(suite.tests)('$description', ({ value, canonical, sha256 }) => {
            expect(canonicalJson(value)).toBe(canonical);
            expect(checksum(value)).toBe(sha256);
        });
    },
);

describe.each(suites<{ definition: unknown; valid: boolean }>('meta-schema'))(
    '%s',
    (_file, suite) => {
        it.each(suite.tests)('$description', ({ definition, valid }) => {
            const result = validateDefinition(definition);
            expect(result.valid, JSON.stringify(result.errors)).toBe(valid);
        });
    },
);

type ExpectedFinding = Pick<LintFinding, 'severity' | 'code' | 'path'>;

function findingSet(findings: readonly ExpectedFinding[]): string[] {
    return [
        ...new Set(findings.map((item) => `${item.severity} ${item.code} ${item.path}`)),
    ].sort();
}

describe.each(
    suites<{ definition: unknown; published?: unknown[]; expected: ExpectedFinding[] }>('lint'),
)('%s', (_file, suite) => {
    it.each(suite.tests)('$description', ({ definition, published, expected }) => {
        expect(findingSet(lint(definition, published ?? []))).toEqual(findingSet(expected));
    });
});

interface EvaluateExpectation {
    readonly errors: readonly ValidationError[];
    readonly data?: JsonValue;
    readonly calculated?: Readonly<Record<string, JsonValue>>;
    readonly visibility?: Readonly<Record<string, boolean>>;
}

interface EvaluateSuite extends Suite<{ data: unknown; expected: EvaluateExpectation }> {
    readonly definition: unknown;
    readonly context: EvaluationContext;
}

function errorSet(errors: readonly ValidationError[]): string[] {
    return [...new Set(errors.map((error) => `${error.pointer} ${error.code}`))].sort();
}

function valueAt(data: JsonValue, pointer: string): JsonValue | undefined {
    return pointer
        .split('/')
        .slice(1)
        .reduce<JsonValue | undefined>((current, token) => {
            if (current === null || typeof current !== 'object') {
                return undefined;
            }
            return (current as Record<string, JsonValue>)[
                token.replace(/~1/g, '/').replace(/~0/g, '~')
            ];
        }, data);
}

function assertEvaluation(result: EvaluationResult, expected: EvaluateExpectation): void {
    expect(errorSet(result.errors)).toEqual(errorSet(expected.errors));
    for (const error of expected.errors.filter((item) => item.params !== undefined)) {
        const actual = result.errors.find(
            (item) => item.pointer === error.pointer && item.code === error.code,
        );
        expect(actual?.params).toEqual(error.params);
    }
    if (expected.data !== undefined) {
        expect(result.data).toEqual(expected.data);
    }
    for (const [pointer, value] of Object.entries(expected.calculated ?? {})) {
        const actual = valueAt(result.data, pointer);
        expect(value === null ? (actual ?? null) : actual, pointer).toEqual(value);
    }
    for (const [pointer, visible] of Object.entries(expected.visibility ?? {})) {
        expect(result.visibility[pointer], pointer).toBe(visible);
    }
}

describe.each(suites<{ data: unknown; expected: EvaluateExpectation }>('evaluate'))(
    '%s',
    (_file, raw) => {
        const suite = raw as EvaluateSuite;
        it.each(suite.tests)('$description', ({ data, expected }) => {
            assertEvaluation(evaluate(suite.definition, data, suite.context), expected);
        });
    },
);
