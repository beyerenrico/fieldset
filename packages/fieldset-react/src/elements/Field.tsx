/** Field chrome: label (or legend), required marker, help text and error message. */
import type { ReactElement, ReactNode } from 'react';
import type { FieldState } from './useField.ts';

function RequiredMarker({ text }: { readonly text: string }): ReactElement {
    return (
        <span className="fsr-required">
            <span aria-hidden="true"> *</span>
            <span className="fsr-sr-only"> ({text})</span>
        </span>
    );
}

function Messages({ field }: { readonly field: FieldState }): ReactElement {
    return (
        <>
            {field.help !== undefined && (
                <p id={field.helpId} className="fsr-help">
                    {field.help}
                </p>
            )}
            {field.errorText !== undefined && (
                <p id={field.errorId} className="fsr-error">
                    {field.errorText}
                </p>
            )}
        </>
    );
}

export interface FieldProps {
    readonly field: FieldState;
    readonly children: ReactNode;
    /** Group of inputs (radio, checkboxes): rendered as fieldset + legend. */
    readonly group?: boolean;
    /** The control renders its own label (single checkbox, toggle). */
    readonly ownLabel?: boolean;
    readonly className?: string;
}

export function Field({
    field,
    children,
    group = false,
    ownLabel = false,
    className,
}: FieldProps): ReactElement {
    const classes = [
        'fsr-field',
        `fsr-field--${field.node.type}`,
        field.error === undefined ? null : 'fsr-field--invalid',
        className ?? null,
    ]
        .filter(Boolean)
        .join(' ');
    const labelClass = field.inCell ? 'fsr-label fsr-sr-only' : 'fsr-label';
    const marker = field.required ? (
        <RequiredMarker text={field.context.messages.requiredMarker} />
    ) : null;
    if (group) {
        return (
            <fieldset
                id={`${field.id}-field`}
                className={classes}
                aria-describedby={field.describedBy}
            >
                <legend className={labelClass}>
                    {field.label}
                    {marker}
                </legend>
                {children}
                <Messages field={field} />
            </fieldset>
        );
    }
    return (
        <div id={`${field.id}-field`} className={classes}>
            {!ownLabel && (
                <label htmlFor={field.id} className={labelClass}>
                    {field.label}
                    {marker}
                </label>
            )}
            {children}
            <Messages field={field} />
        </div>
    );
}

export { RequiredMarker };
