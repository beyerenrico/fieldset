/** Edit pipeline and touched state of a renderer instance. */
import type {
    DefinitionModel,
    EvaluationContext,
    EvaluationResult,
    JsonObject,
    JsonValue,
} from '@form-schema-app/form-schema-js';
import { useCallback, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { ValueUpdater } from '../context.tsx';
import { materializeDefaults } from '../data/materialize.ts';
import { getAt, setAt, type DataPath } from '../data/path.ts';
import { evaluateCached, remember } from './evaluation-cache.ts';

export interface RendererState {
    readonly result: EvaluationResult;
    readonly touched: ReadonlySet<string>;
    readonly setValue: (path: DataPath, value: JsonValue | undefined) => void;
    readonly updateValue: (path: DataPath, updater: ValueUpdater) => void;
    readonly touch: (pointer: string) => void;
    readonly touchAll: (pointers: Iterable<string>) => void;
}

export function useRendererState(
    model: DefinitionModel,
    data: JsonObject,
    context: EvaluationContext,
    onChange: ((data: JsonObject, result: EvaluationResult) => void) | undefined,
): RendererState {
    const result = useMemo(() => evaluateCached(model, data, context), [model, data, context]);
    const latest = useRef({ data, result });
    const [touched, setTouched] = useState<ReadonlySet<string>>(() => new Set());

    useLayoutEffect(() => {
        latest.current = { data, result };
    }, [data, result]);

    const updateValue = useCallback(
        (path: DataPath, updater: ValueUpdater): void => {
            const current = latest.current;
            const base = materializeDefaults(model, current.data, current.result.data);
            const next = setAt(base, path, updater(getAt(base, path)));
            const nextResult = evaluateCached(model, next, context);
            const out = materializeDefaults(model, next, nextResult.data);
            remember(out, { model, context, result: nextResult });
            latest.current = { data: out, result: nextResult };
            onChange?.(out, nextResult);
        },
        [model, context, onChange],
    );
    const setValue = useCallback(
        (path: DataPath, value: JsonValue | undefined): void => {
            updateValue(path, () => value);
        },
        [updateValue],
    );
    const touch = useCallback((pointer: string): void => {
        setTouched((previous) =>
            previous.has(pointer) ? previous : new Set(previous).add(pointer),
        );
    }, []);
    const touchAll = useCallback((pointers: Iterable<string>): void => {
        setTouched((previous) => {
            const next = new Set(previous);
            for (const pointer of pointers) {
                next.add(pointer);
            }
            return next.size === previous.size ? previous : next;
        });
    }, []);
    return { result, touched, setValue, updateValue, touch, touchAll };
}
