/** Typed API errors. */

export interface ValidationDetail {
    readonly pointer: string;
    readonly code: string;
    readonly message?: string;
}

/** The server answered with an error status or `success: false`. */
export class ApiError extends Error {
    override readonly name = 'ApiError';
    readonly status: number;
    readonly code: string;
    readonly details: unknown;

    constructor(status: number, code: string, message: string, details: unknown = null) {
        super(message);
        this.status = status;
        this.code = code;
        this.details = details;
    }
}

/** `fetch` itself failed (offline, DNS, CORS): the request may be retried later. */
export class NetworkError extends Error {
    override readonly name = 'NetworkError';
}

/** The session is gone; the user must log in again. Local data is kept. */
export class ReauthRequiredError extends Error {
    override readonly name = 'ReauthRequiredError';
    /** True when a known device's session expired (false: never signed in on this device). */
    readonly expired: boolean;

    constructor(message: string, expired = true) {
        super(message);
        this.expired = expired;
    }
}

/** The server asked this device to wipe; local data has been cleared. */
export class DeviceWipedError extends Error {
    override readonly name = 'DeviceWipedError';
}

export function isApiError(error: unknown, status?: number, code?: string): error is ApiError {
    return (
        error instanceof ApiError &&
        (status === undefined || error.status === status) &&
        (code === undefined || error.code === code)
    );
}

export function isConflict(error: unknown, code?: string): error is ApiError {
    return isApiError(error, 409, code);
}

function isDetail(value: unknown): value is ValidationDetail {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    return typeof record.pointer === 'string' && typeof record.code === 'string';
}

export function isValidationError(error: unknown): error is ApiError {
    return isApiError(error, undefined, 'validation_failed');
}

/** Pointer details of a `validation_failed` error (empty for anything else). */
export function validationDetails(error: unknown): ValidationDetail[] {
    if (!isValidationError(error) || !Array.isArray(error.details)) {
        return [];
    }
    return (error.details as unknown[]).filter(isDetail);
}

export function isAuthLost(error: unknown): boolean {
    return error instanceof ReauthRequiredError || error instanceof DeviceWipedError;
}
