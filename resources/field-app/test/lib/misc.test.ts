import { describe, expect, it } from 'vitest';
import { shouldRetry } from '../../src/App.tsx';
import {
    ApiError,
    NetworkError,
    ReauthRequiredError,
    isApiError,
} from '../../src/lib/api/errors.ts';
import { diffDrafts, summarize } from '../../src/lib/autosave/conflict-diff.ts';
import {
    parseDetailSearch,
    parseFillSearch,
    parseLoginSearch,
    parseSubmissionsSearch,
} from '../../src/routes/search.ts';
import { filterForms } from '../../src/screens/CatalogScreen.tsx';
import { DEFINITION, FORMS } from '../utils/fixtures.ts';

const texts = {
    empty: '—',
    yes: 'Yes',
    no: 'No',
    rows: (count: number) => `${String(count)} rows`,
};

describe('conflict diff', () => {
    it('lists differing top-level data fields with labels (no calculated values)', () => {
        const diffs = diffDrafts(
            DEFINITION,
            { customer: 'Mine', items: [{ name: 'a' }], count: '1' },
            { customer: 'Server', items: [{ name: 'a' }], count: '2' },
        );
        expect(diffs).toEqual([
            { key: 'customer', label: 'Customer', mine: 'Mine', server: 'Server' },
        ]);
    });

    it('summarizes values', () => {
        expect(summarize(undefined, texts)).toBe('—');
        expect(summarize('', texts)).toBe('—');
        expect(summarize(true, texts)).toBe('Yes');
        expect(summarize(false, texts)).toBe('No');
        expect(summarize([{ a: 1 }, { a: 2 }], texts)).toBe('2 rows');
        expect(summarize(['a', 'b'], texts)).toBe('a, b');
        expect(summarize([], texts)).toBe('—');
        expect(summarize({ lat: 1 }, texts)).toBe('{"lat":1}');
        expect(summarize('x'.repeat(80), texts)).toHaveLength(60);
        expect(summarize('12.50', texts)).toBe('12.50');
    });
});

describe('search params', () => {
    it('parses and narrows untrusted values', () => {
        expect(parseLoginSearch({ notice: 'wiped' })).toEqual({ notice: 'wiped' });
        expect(parseLoginSearch({ notice: 'evil' })).toEqual({});
        expect(parseFillSearch({ formId: '3', versionId: 7 })).toEqual({ formId: 3, versionId: 7 });
        expect(parseFillSearch({ formId: '3' })).toEqual({});
        expect(parseFillSearch({ formId: '-1', versionId: 'x' })).toEqual({});
        expect(parseSubmissionsSearch({ status: 'draft' })).toEqual({ status: 'draft' });
        expect(parseSubmissionsSearch({ status: 'nope' })).toEqual({});
        expect(parseDetailSearch({ submitted: 'true' })).toEqual({ submitted: true });
        expect(parseDetailSearch({})).toEqual({});
    });
});

describe('query retry policy', () => {
    it('does not retry 4xx or lost sessions; retries others twice', () => {
        expect(shouldRetry(0, new ApiError(404, 'not_found', 'x'))).toBe(false);
        expect(shouldRetry(0, new ReauthRequiredError('x'))).toBe(false);
        expect(shouldRetry(0, new ApiError(500, 'http_500', 'x'))).toBe(true);
        expect(shouldRetry(1, new NetworkError('x'))).toBe(true);
        expect(shouldRetry(2, new NetworkError('x'))).toBe(false);
        expect(isApiError(new ApiError(409, 'a', 'b'), 409, 'a')).toBe(true);
    });
});

describe('catalog filter', () => {
    it('filters by name, key and category', () => {
        expect(filterForms(FORMS, 'insp', '*').map((form) => form.id)).toEqual([4]);
        expect(filterForms(FORMS, 'TIMESHEET', '*').map((form) => form.id)).toEqual([5]);
        expect(filterForms(FORMS, '', 'Service').map((form) => form.id)).toEqual([3]);
        expect(filterForms(FORMS, '', '').map((form) => form.id)).toEqual([5]);
        expect(filterForms(FORMS, '  ', '*')).toHaveLength(3);
    });
});
