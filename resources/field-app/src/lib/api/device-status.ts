/**
 * Session recovery (§9.3): on 401/419 ask `POST /auth/device-status` — authenticated by the device
 * secret, never by the session — and act on `ok` / `reauth` / `wipe`. Concurrent callers share one
 * recovery. This module never triggers itself (device-status uses the raw request).
 */
import { getDeviceRecord, dropDeviceSecret } from '../device/credentials.ts';
import { clearLocalData } from '../session/local-data.ts';
import { ApiError, DeviceWipedError, ReauthRequiredError } from './errors.ts';
import { rawRequest } from './http.ts';
import { CSRF_COOKIE_PATH, paths } from './paths.ts';

export type DeviceStatus = 'ok' | 'reauth' | 'wipe' | 'unregistered';

let recovery: Promise<void> | null = null;

export async function fetchDeviceStatus(): Promise<DeviceStatus> {
    const device = await getDeviceRecord();
    if (device === undefined || device.secret === null) {
        return 'unregistered';
    }
    try {
        const response = await rawRequest<{ status?: unknown }>(paths.deviceStatus, {
            method: 'POST',
            tenant: false,
            headers: { 'X-Device-Id': device.deviceUuid, 'X-Device-Secret': device.secret },
        });
        const status = response.data.status;
        return status === 'ok' || status === 'wipe' ? status : 'reauth';
    } catch (error) {
        if (error instanceof ApiError) {
            if (error.status === 401) {
                // device_unknown: the secret is stale → re-register after the next login.
                await dropDeviceSecret();
            }
            return 'reauth';
        }
        throw error;
    }
}

export async function refreshCsrfCookie(): Promise<void> {
    await rawRequest<undefined>(CSRF_COOKIE_PATH, { tenant: false });
}

async function recover(): Promise<void> {
    const status = await fetchDeviceStatus();
    if (status === 'ok') {
        await refreshCsrfCookie();
        return;
    }
    if (status === 'wipe') {
        await clearLocalData({ keepDeviceId: false });
        throw new DeviceWipedError('This device was signed out and its data removed.');
    }
    throw new ReauthRequiredError('Please sign in again.', status === 'reauth');
}

/** Resolves when the request may be retried; throws `ReauthRequiredError`/`DeviceWipedError`. */
export function recoverSession(): Promise<void> {
    recovery ??= recover().finally(() => {
        recovery = null;
    });
    return recovery;
}
