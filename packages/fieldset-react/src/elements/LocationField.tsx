/** location: explicit opt-in capture via navigator.geolocation. */
import type { ElementNode, JsonValue } from '@fieldset/fieldset-js';
import { useState, type ReactElement } from 'react';
import { isJsonObject, type DataPath } from '../data/path.ts';
import { formatNumber } from '../format/intl.ts';
import { interpolate, type RendererMessages } from '../i18n/messages.ts';
import { captureLocation, LocationError, type LocationFailure } from '../media/geolocation.ts';
import { Field } from './Field.tsx';
import { inputAria, useField } from './useField.ts';

type Status = 'idle' | 'locating' | LocationFailure;

const FAILURE_KEYS = {
    denied: 'locationDenied',
    unavailable: 'locationUnavailable',
    timeout: 'locationTimeout',
} as const satisfies Record<LocationFailure, keyof RendererMessages>;

function describe(
    value: JsonValue | undefined,
    locale: string,
    messages: RendererMessages,
): string | null {
    if (!isJsonObject(value)) {
        return null;
    }
    const { lat, lng, accuracy } = value;
    if (typeof lat !== 'number' || typeof lng !== 'number' || typeof accuracy !== 'number') {
        return null;
    }
    return interpolate(messages.locationValue, {
        lat: formatNumber(lat, locale, 6),
        lng: formatNumber(lng, locale, 6),
        accuracy: formatNumber(accuracy, locale, 0),
    });
}

export function LocationField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const field = useField(node, base);
    const { messages, locale } = field.context;
    const [status, setStatus] = useState<Status>('idle');
    const text = describe(field.value, locale, messages);

    const capture = async (): Promise<void> => {
        const edit = field.context.startEdit(field.path);
        setStatus('locating');
        try {
            const value = await captureLocation();
            edit.update(() => value);
            setStatus('idle');
        } catch (error: unknown) {
            setStatus(error instanceof LocationError ? error.reason : 'unavailable');
        } finally {
            field.touch();
            edit.finish();
        }
    };
    const failure =
        status === 'idle' || status === 'locating' ? null : messages[FAILURE_KEYS[status]];
    return (
        <Field field={field} group>
            {text !== null && <p className="fsr-location__value">{text}</p>}
            {!field.disabled && (
                <div className="fsr-actions">
                    <button
                        {...inputAria(field)}
                        type="button"
                        className="fsr-button fsr-button--secondary"
                        disabled={status === 'locating'}
                        aria-busy={status === 'locating' ? true : undefined}
                        onClick={() => {
                            void capture();
                        }}
                    >
                        {status === 'locating' ? messages.locating : messages.captureLocation}
                    </button>
                    {text !== null && (
                        <button
                            type="button"
                            className="fsr-button fsr-button--quiet"
                            onClick={() => {
                                field.setValue(null);
                                field.touch();
                            }}
                        >
                            {messages.removeLocation}
                        </button>
                    )}
                </div>
            )}
            {text === null && field.disabled && <p className="fsr-muted">{messages.empty}</p>}
            {failure !== null && (
                <p className="fsr-error" role="alert">
                    {failure}
                </p>
            )}
        </Field>
    );
}
