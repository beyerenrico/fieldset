/** One-shot location capture (only after an explicit user action). */
import type { JsonObject } from '@form-schema-app/form-schema-js';
import { toRfc3339 } from '../format/datetime.ts';

export type LocationFailure = 'denied' | 'unavailable' | 'timeout';

export class LocationError extends Error {
    override readonly name = 'LocationError';
    readonly reason: LocationFailure;

    constructor(reason: LocationFailure) {
        super(reason);
        this.reason = reason;
    }
}

function reasonOf(error: GeolocationPositionError): LocationFailure {
    if (error.code === error.PERMISSION_DENIED) {
        return 'denied';
    }
    return error.code === error.TIMEOUT ? 'timeout' : 'unavailable';
}

/** Resolves to a spec location value `{ lat, lng, accuracy, capturedAt }`. */
export function captureLocation(): Promise<JsonObject> {
    return new Promise((resolve, reject) => {
        if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
            reject(new LocationError('unavailable'));
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (position) => {
                resolve({
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    accuracy: Math.max(0, position.coords.accuracy),
                    capturedAt: toRfc3339(new Date(position.timestamp || Date.now())),
                });
            },
            (error) => {
                reject(new LocationError(reasonOf(error)));
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    });
}
