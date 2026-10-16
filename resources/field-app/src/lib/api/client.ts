/** API requests with session recovery: a 401/419 runs the device-status flow and retries once. */
import { recoverSession } from './device-status.ts';
import { ApiError, ReauthRequiredError } from './errors.ts';
import { rawRequest, type ApiResponse, type RequestOptions } from './http.ts';

function isSessionError(error: unknown): boolean {
    return error instanceof ApiError && (error.status === 401 || error.status === 419);
}

export async function apiRequest<T>(
    path: string,
    options: RequestOptions = {},
): Promise<ApiResponse<T>> {
    try {
        return await rawRequest<T>(path, options);
    } catch (error) {
        if (options.recover === false || !isSessionError(error)) {
            throw error;
        }
    }
    await recoverSession();
    try {
        return await rawRequest<T>(path, options);
    } catch (error) {
        if (isSessionError(error)) {
            throw new ReauthRequiredError('Please sign in again.');
        }
        throw error;
    }
}
