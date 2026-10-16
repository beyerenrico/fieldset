/** <FormRenderer>: the one renderer for the Field App and the builder preview. */
import {
    parseDefinition,
    type ElementNode,
    type EvaluationContext,
} from '@form-schema-app/form-schema-js';
import {
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type ReactElement,
} from 'react';
import { RendererContext, type RendererContextValue } from './context.tsx';
import { resolveMessages } from './i18n/messages.ts';
import { ScrollLayout } from './layout/ScrollLayout.tsx';
import { WizardLayout } from './layout/WizardLayout.tsx';
import { collectErrors, firstByPointer } from './state/errors.ts';
import { beginEdit, createPendingEdits } from './state/pending-edits.ts';
import { createRevealRegistry } from './state/reveal.ts';
import { useRendererState } from './state/useRendererState.ts';
import { listInstances, visiblePageIndexes } from './structure/instances.ts';
import type { FieldError, FormRendererProps } from './types.ts';

const EMPTY_CONTEXT: EvaluationContext = {};
const NO_ERRORS: readonly FieldError[] = [];

function useSummaryFocus(showErrors: boolean): number {
    const [state, setState] = useState({ shown: showErrors, key: showErrors ? 1 : 0 });
    if (state.shown !== showErrors) {
        setState({ shown: showErrors, key: showErrors ? state.key + 1 : state.key });
    }
    return state.key;
}

export function FormRenderer(props: FormRendererProps): ReactElement {
    const {
        definition,
        data,
        attachments,
        mode = 'fill',
        readOnly = false,
        showErrors = false,
    } = props;
    const context = props.context ?? EMPTY_CONTEXT;
    const external = props.errors ?? NO_ERRORS;
    const model = useMemo(() => parseDefinition(definition), [definition]);
    const state = useRendererState(model, data, context, props.onChange);
    const { result, touched } = state;
    const generatedId = useId();
    const idBase = props.idPrefix ?? `fsr${generatedId.replace(/[^A-Za-z0-9]/g, '')}`;
    const locale = model.definition.locale;
    const messages = useMemo(
        () => resolveMessages(props.uiLocale ?? locale, props.messages),
        [props.uiLocale, locale, props.messages],
    );
    const [reveal] = useState(createRevealRegistry);
    const [edits] = useState(createPendingEdits);
    const summaryFocusKey = useSummaryFocus(showErrors);
    const { onEvaluate, onAsyncWork } = props;
    // Async completions write through the latest pipeline (definition or onChange may change).
    const writer = useRef(state.updateExisting);

    useLayoutEffect(() => {
        writer.current = state.updateExisting;
    }, [state.updateExisting]);

    useEffect(() => {
        onEvaluate?.(result);
    }, [result, onEvaluate]);

    const instances = useMemo(
        () => listInstances(model, data, result.data),
        [model, data, result.data],
    );
    const entries = useMemo(
        () => collectErrors(instances, result.visibility, result.errors, external, messages),
        [instances, result, external, messages],
    );
    const pages = useMemo(
        () => visiblePageIndexes(model, result.visibility),
        [model, result.visibility],
    );
    const pageOf = useMemo(
        () => new Map(instances.map((instance) => [instance.pointer, instance.page])),
        [instances],
    );
    const clientErrors = useMemo(() => firstByPointer(result.errors), [result.errors]);
    const externalErrors = useMemo(() => firstByPointer(external), [external]);
    const except = props.readOnlyExcept;

    const value: RendererContextValue = {
        model,
        data,
        result,
        locale,
        messages,
        mode,
        idBase,
        attachments,
        setValue: state.setValue,
        updateValue: state.updateValue,
        startEdit: (path) =>
            beginEdit(
                edits,
                path,
                (target, updater) => writer.current(target, updater),
                onAsyncWork,
            ),
        rowRemoved: edits.rowRemoved,
        touch: state.touch,
        visibleError: (pointer) =>
            externalErrors.get(pointer) ??
            (showErrors || touched.has(pointer) ? clientErrors.get(pointer) : undefined),
        hasRequiredError: (pointer) => clientErrors.get(pointer)?.code === 'required',
        isReadOnly: (node: ElementNode, pointer: string) =>
            (readOnly && !(except ?? []).includes(node.element.type)) ||
            result.readOnly[pointer] === true,
        reveal,
    };
    const layout = props.layout ?? model.definition.settings?.layout ?? 'scroll';
    const classes = ['fsr-root', `fsr-root--${layout}`, props.className ?? null]
        .filter(Boolean)
        .join(' ');
    return (
        <RendererContext.Provider value={value}>
            <div className={classes} lang={locale} data-mode={mode}>
                {mode === 'preview' && (
                    <p className="fsr-banner" role="note">
                        {messages.previewBanner}
                    </p>
                )}
                {layout === 'wizard' ? (
                    <WizardLayout
                        pages={pages}
                        entries={entries}
                        showAll={showErrors}
                        summaryFocusKey={summaryFocusKey}
                        primaryAction={props.primaryAction}
                        pageOf={pageOf}
                        pagePointers={(page) =>
                            instances
                                .filter((instance) => instance.page === page)
                                .map((instance) => instance.pointer)
                        }
                        touchAll={state.touchAll}
                        onPageChange={props.onPageChange}
                    />
                ) : (
                    <ScrollLayout
                        pages={pages}
                        summary={showErrors ? entries : []}
                        summaryFocusKey={summaryFocusKey}
                        primaryAction={props.primaryAction}
                    />
                )}
            </div>
        </RendererContext.Provider>
    );
}
