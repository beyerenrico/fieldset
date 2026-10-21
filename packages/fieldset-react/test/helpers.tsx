import type { EvaluationResult, FormDefinition, JsonObject } from '@fieldset/fieldset-js';
import { render } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState, type ReactElement } from 'react';
import { vi } from 'vitest';
import { createMemoryAttachmentAdapter } from '../src/attachments/memory-adapter.ts';
import { FormRenderer } from '../src/FormRenderer.tsx';
import type { FormRendererProps } from '../src/types.ts';

export type HarnessProps = Partial<Omit<FormRendererProps, 'definition' | 'data' | 'onChange'>>;

export function renderForm(
    definition: FormDefinition,
    initialData: JsonObject = {},
    props: HarnessProps = {},
) {
    const onChange = vi.fn<(data: JsonObject, result: EvaluationResult) => void>();
    const adapter = props.attachments ?? createMemoryAttachmentAdapter();
    function Harness(extra: HarnessProps): ReactElement {
        const [data, setData] = useState<JsonObject>(initialData);
        return (
            <FormRenderer
                definition={definition}
                data={data}
                attachments={adapter}
                idPrefix="t"
                onChange={(next, result) => {
                    setData(next);
                    onChange(next, result);
                }}
                {...extra}
            />
        );
    }
    const user = userEvent.setup();
    const view = render(<Harness {...props} />);
    return {
        ...view,
        user,
        onChange,
        adapter,
        lastData: (): JsonObject => onChange.mock.lastCall?.[0] ?? initialData,
        update: (next: HarnessProps) => {
            view.rerender(<Harness {...props} {...next} />);
        },
    };
}

/** Replaces narrow/no-break spaces produced by Intl with plain spaces. */
export function plain(text: string | null | undefined): string {
    return (text ?? '').replace(/[\u00a0\u202f]/g, ' ');
}
