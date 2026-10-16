/** Registers this install after login (§9.3). The server stores only a hash of the secret. */
import { registerDevice } from '../api/endpoints.ts';
import { newId } from '../ids.ts';
import { getDeviceRecord, saveDeviceRecord, type DeviceRecord } from './credentials.ts';

const BROWSERS: readonly (readonly [RegExp, string])[] = [
    [/Edg\//, 'Edge'],
    [/Firefox\//, 'Firefox'],
    [/(Chrome|CriOS)\//, 'Chrome'],
    [/Safari\//, 'Safari'],
];

const SYSTEMS: readonly (readonly [RegExp, string])[] = [
    [/Android/, 'Android'],
    [/iPhone|iPad|iPod/, 'iOS'],
    [/Windows/, 'Windows'],
    [/Mac OS X|Macintosh/, 'macOS'],
    [/Linux/, 'Linux'],
];

function pick(list: readonly (readonly [RegExp, string])[], ua: string, fallback: string): string {
    return list.find(([pattern]) => pattern.test(ua))?.[1] ?? fallback;
}

/** Short human-readable device name, e.g. "Chrome on Android". */
export function deviceName(ua: string = navigator.userAgent): string {
    return `${pick(BROWSERS, ua, 'Browser')} on ${pick(SYSTEMS, ua, 'unknown system')}`;
}

/** Re-registers the stored uuid (rotating the secret) or creates a new device. */
export async function ensureDeviceRegistered(): Promise<DeviceRecord> {
    const existing = await getDeviceRecord();
    const registration = await registerDevice({
        device_uuid: existing?.deviceUuid ?? newId(),
        name: deviceName(),
        platform: 'web',
    });
    const record: DeviceRecord = {
        deviceUuid: registration.device_uuid,
        secret: registration.secret,
    };
    await saveDeviceRecord(record);
    return record;
}
