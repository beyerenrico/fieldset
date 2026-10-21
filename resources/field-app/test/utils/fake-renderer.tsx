/** Black-box stand-in for <FormRenderer> in screen tests (the real one is tested in its package). */
import type { EvaluationResult } from '@fieldset/fieldset-js';
import type { FormRendererProps } from '@fieldset/fieldset-react';
import type { ReactNode } from 'react';

const RESULT: EvaluationResult = {
    data: {},
    errors: [],
    visibility: {},
    required: {},
    readOnly: {},
};

export function FakeRenderer(props: FormRendererProps): ReactNode {
    const { data, onChange, primaryAction, attachments } = props;
    const photo = typeof data.photos === 'string' ? attachments.get?.(data.photos) : undefined;
    return (
        <div
            data-testid="renderer"
            data-readonly={String(props.readOnly ?? false)}
            data-show-errors={String(props.showErrors ?? false)}
            data-ui-locale={props.uiLocale}
            data-except={(props.readOnlyExcept ?? []).join(',')}
        >
            <pre data-testid="renderer-data">{JSON.stringify(data)}</pre>
            {photo?.url === undefined ? null : <img alt="photo preview" src={photo.url} />}
            <ul aria-label="renderer errors">
                {(props.errors ?? []).map((error) => (
                    <li key={error.pointer}>{`${error.pointer}:${error.code}`}</li>
                ))}
            </ul>
            <button
                type="button"
                onClick={() => onChange?.({ ...data, customer: 'Edited' }, RESULT)}
            >
                fake-edit
            </button>
            <button
                type="button"
                onClick={() => {
                    attachments
                        .upload({
                            file: new Blob(['x'], { type: 'image/jpeg' }),
                            fileName: 'a.jpg',
                            kind: 'photo',
                            elementKey: 'photos',
                            pointer: '/photos',
                        })
                        .then((info) => onChange?.({ ...data, photos: info.id }, RESULT))
                        .catch(() => undefined);
                }}
            >
                fake-upload
            </button>
            {primaryAction === undefined ? null : (
                <button
                    type="button"
                    disabled={primaryAction.disabled}
                    onClick={primaryAction.onClick}
                >
                    {primaryAction.label}
                </button>
            )}
        </div>
    );
}
