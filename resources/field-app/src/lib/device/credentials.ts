/** Device credentials in IndexedDB: the uuid survives logout, the secret is dropped when stale. */
import { kvGet, kvSet } from '../storage/kv.ts';

const KEY = 'device';

export interface DeviceRecord {
    readonly deviceUuid: string;
    /** `null` when the server no longer knows the secret (re-register after the next login). */
    readonly secret: string | null;
}

function isRecord(value: unknown): value is DeviceRecord {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const record = value as Record<string, unknown>;
    return (
        typeof record.deviceUuid === 'string' &&
        (typeof record.secret === 'string' || record.secret === null)
    );
}

export async function getDeviceRecord(): Promise<DeviceRecord | undefined> {
    const value = await kvGet<unknown>(KEY);
    return isRecord(value) ? value : undefined;
}

export async function saveDeviceRecord(record: DeviceRecord): Promise<void> {
    await kvSet(KEY, record);
}

/** Keeps the uuid but forgets the secret (`device_unknown`). */
export async function dropDeviceSecret(): Promise<void> {
    const record = await getDeviceRecord();
    if (record !== undefined) {
        await saveDeviceRecord({ deviceUuid: record.deviceUuid, secret: null });
    }
}
