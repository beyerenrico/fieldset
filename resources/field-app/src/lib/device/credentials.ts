/** Device credentials in the offline store: the uuid survives logout, the secret is dropped when stale. */
import { getMeta, setMeta } from '../db/db.ts';
import type { DeviceRecord } from '../db/schema.ts';

export type { DeviceRecord } from '../db/schema.ts';

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
    const value: unknown = await getMeta('device');
    return isRecord(value) ? value : undefined;
}

export async function saveDeviceRecord(record: DeviceRecord): Promise<void> {
    await setMeta('device', record);
}

/** Keeps the uuid but forgets the secret (`device_unknown`). */
export async function dropDeviceSecret(): Promise<void> {
    const record = await getDeviceRecord();
    if (record !== undefined) {
        await saveDeviceRecord({ deviceUuid: record.deviceUuid, secret: null });
    }
}
