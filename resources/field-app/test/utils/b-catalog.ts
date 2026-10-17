/** In-memory catalog backend for the catalog cache tests (agent B; mutable fixture on purpose). */
import { http, type HttpResponseResolver } from 'msw';
import type { FormSummary, FormVersion } from '../../src/lib/api/types.ts';
import { fail, ok } from './backend.ts';
import { makeVersion } from './fixtures.ts';

export const summary = (id: number, versionId: number, checksum = `c${String(versionId)}`) =>
    ({
        id,
        key: `form_${String(id)}`,
        name: `Form ${String(id)}`,
        category: null,
        version: { id: versionId, version: 1, checksum },
    }) satisfies FormSummary;

export const version = (versionId: number, formId: number, checksum = `c${String(versionId)}`) =>
    ({ ...makeVersion(versionId, formId), checksum }) satisfies FormVersion;

export interface CatalogBackend {
    /** Forms of GET /forms (the full fillable list). */
    forms: FormSummary[];
    /** `forms` of the next manifest (the delta since the cursor). */
    manifestForms: FormSummary[];
    fillable: number[];
    /** `retired_version_ids` of the next manifest. */
    retired: number[];
    cursor: string;
    /** Version checksum overrides by version id. */
    checksums: Map<number, string>;
    /** Version ids that answer with this status instead. */
    failures: Map<number, number>;
    readonly calls: {
        manifest: (string | null)[];
        forms: number;
        versions: number[];
        orgHeaders: (string | null)[];
    };
}

export function createCatalogBackend(initial: Partial<CatalogBackend> = {}) {
    const state: CatalogBackend = {
        forms: [],
        manifestForms: [],
        fillable: [],
        retired: [],
        cursor: 'c1',
        checksums: new Map(),
        failures: new Map(),
        calls: { manifest: [], forms: 0, versions: [], orgHeaders: [] },
        ...initial,
    };
    const track: (resolver: HttpResponseResolver) => HttpResponseResolver =
        (resolver) => (info) => {
            state.calls.orgHeaders.push(info.request.headers.get('X-Organization-Id'));
            return resolver(info);
        };
    const handlers = [
        http.get(
            '*/api/v1/sync/manifest',
            track(({ request }) => {
                state.calls.manifest.push(new URL(request.url).searchParams.get('since'));
                return ok({
                    cursor: state.cursor,
                    forms: state.manifestForms,
                    fillable_form_ids: state.fillable,
                    revoked_form_ids: [],
                    retired_version_ids: state.retired,
                    tasks: [],
                });
            }),
        ),
        http.get(
            '*/api/v1/forms',
            track(() => {
                state.calls.forms++;
                return ok(state.forms);
            }),
        ),
        http.get(
            '*/api/v1/forms/:form/versions/:version',
            track(({ params }) => {
                const id = Number(params.version);
                state.calls.versions.push(id);
                const failure = state.failures.get(id);
                if (failure !== undefined) {
                    return fail(failure, failure === 404 ? 'not_found' : `http_${String(failure)}`);
                }
                return ok(version(id, Number(params.form), state.checksums.get(id)));
            }),
        ),
    ];
    return { state, handlers };
}
