/** Low-level fetch wrapper: same-origin credentials, XSRF, envelope parsing, typed errors. */
import { ApiError, NetworkError, type ValidationDetail } from './errors.ts';
import { getOrganizationId } from '../session/org-store.ts';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
export type Query = Readonly<Record<string, string | number | undefined>>;

export interface RequestOptions {
    readonly method?: HttpMethod;
    /** JSON body. */
    readonly body?: unknown;
    /** Multipart body (wins over `body`). */
    readonly formData?: FormData;
    readonly query?: Query;
    readonly headers?: Readonly<Record<string, string>>;
    /** Send `X-Organization-Id` (default true). */
    readonly tenant?: boolean;
    /** Run the 401/419 device-status recovery (default true). */
    readonly recover?: boolean;
    /** `blob` returns the raw body (file downloads); errors are still parsed as JSON. */
    readonly responseType?: 'json' | 'blob';
    /** Let the request outlive the page (page hide); only honoured for JSON bodies ≤ 64 KiB. */
    readonly keepalive?: boolean;
}

export interface ApiResponse<T> {
    readonly data: T;
    readonly meta: Readonly<Record<string, unknown>> | null;
    readonly status: number;
    readonly headers: Headers;
}

const MUTATING = new Set<HttpMethod>(['POST', 'PUT', 'PATCH', 'DELETE']);
/** Browsers reject keepalive requests whose (pending) bodies exceed 64 KiB. */
const KEEPALIVE_LIMIT = 64 * 1024;

export function readCookie(name: string): string | null {
    const match = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(`${name}=`));
    if (match === undefined) {
        return null;
    }
    try {
        return decodeURIComponent(match.slice(name.length + 1));
    } catch {
        return null;
    }
}

export function buildUrl(path: string, query: Query = {}): string {
    const url = new URL(path, window.location.origin);
    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== '') {
            url.searchParams.set(key, String(value));
        }
    }
    return url.toString();
}

function buildHeaders(method: HttpMethod, options: RequestOptions): Headers {
    const headers = new Headers({
        Accept: options.responseType === 'blob' ? '*/*' : 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    });
    if (options.body !== undefined && options.formData === undefined) {
        headers.set('Content-Type', 'application/json');
    }
    const xsrf = MUTATING.has(method) ? readCookie('XSRF-TOKEN') : null;
    if (xsrf !== null) {
        headers.set('X-XSRF-TOKEN', xsrf);
    }
    const org = options.tenant === false ? null : getOrganizationId();
    if (org !== null) {
        headers.set('X-Organization-Id', String(org));
    }
    for (const [key, value] of Object.entries(options.headers ?? {})) {
        headers.set(key, value);
    }
    return headers;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Laravel's default 422 `{ message, errors: { field: [msg] } }` → pointer details. */
function laravelDetails(errors: unknown): ValidationDetail[] {
    if (!isRecord(errors)) {
        return [];
    }
    return Object.entries(errors).map(([field, messages]) => {
        const first: unknown = Array.isArray(messages) ? messages[0] : messages;
        const pointer = `/${field.split('.').join('/')}`;
        return typeof first === 'string'
            ? { pointer, code: 'invalid', message: first }
            : { pointer, code: 'invalid' };
    });
}

function errorFrom(status: number, body: unknown): ApiError {
    const fallback = `http_${String(status)}`;
    if (!isRecord(body)) {
        return new ApiError(status, fallback, `Request failed with status ${String(status)}`);
    }
    const error = isRecord(body.error) ? body.error : null;
    if (error !== null) {
        const code = typeof error.code === 'string' ? error.code : fallback;
        const message = typeof error.message === 'string' ? error.message : code;
        return new ApiError(status, code, message, error.details ?? null);
    }
    const message = typeof body.message === 'string' ? body.message : fallback;
    if (status === 422 && 'errors' in body) {
        return new ApiError(status, 'validation_failed', message, laravelDetails(body.errors));
    }
    return new ApiError(status, typeof body.code === 'string' ? body.code : fallback, message);
}

async function readBody(response: Response): Promise<unknown> {
    if (response.status === 204) {
        return undefined;
    }
    const text = await response.text();
    if (text === '') {
        return undefined;
    }
    try {
        return JSON.parse(text) as unknown;
    } catch {
        return text;
    }
}

/** Parses the envelope; a body without boolean `success` is the payload itself. */
export async function parseResponse<T>(response: Response): Promise<ApiResponse<T>> {
    const body = await readBody(response);
    const envelope = isRecord(body) && typeof body.success === 'boolean';
    if (!response.ok || (envelope && body.success === false)) {
        throw errorFrom(response.status, body);
    }
    const data = (envelope ? body.data : body) as T;
    const meta = envelope && isRecord(body.meta) ? body.meta : null;
    return { data, meta, status: response.status, headers: response.headers };
}

/** One HTTP request without session recovery. */
export async function rawRequest<T>(
    path: string,
    options: RequestOptions = {},
): Promise<ApiResponse<T>> {
    const method = options.method ?? 'GET';
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: buildHeaders(method, options),
    };
    if (options.formData !== undefined) {
        init.body = options.formData;
    } else if (options.body !== undefined) {
        init.body = JSON.stringify(options.body);
        if (
            options.keepalive === true &&
            new TextEncoder().encode(init.body).byteLength <= KEEPALIVE_LIMIT
        ) {
            init.keepalive = true;
        }
    }
    let response: Response;
    try {
        response = await fetch(buildUrl(path, options.query), init);
    } catch (error) {
        throw new NetworkError(error instanceof Error ? error.message : 'Network request failed');
    }
    if (options.responseType === 'blob' && response.ok) {
        const data = (await response.blob()) as T;
        return { data, meta: null, status: response.status, headers: response.headers };
    }
    return parseResponse<T>(response);
}
