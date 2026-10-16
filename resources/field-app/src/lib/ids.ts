import { v7 } from 'uuid';

/** Client-generated ids for submissions, attachments, devices and idempotency keys (UUIDv7). */
export function newId(): string {
    return v7();
}

export const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
