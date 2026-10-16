/** repeater: rows as cards (default) or a table; add/remove within minItems/maxItems. */
import type { ElementNode, JsonValue } from '@form-schema-app/form-schema-js';
import { useEffect, useRef, type ReactElement } from 'react';
import { useRenderer } from '../context.tsx';
import { getAt, pointerId, toPointer, type DataPath } from '../data/path.ts';
import { errorMessage } from '../i18n/errors.ts';
import { RequiredMarker } from '../elements/Field.tsx';
import { firstInput, rowCount } from '../structure/instances.ts';
import { RepeaterCards } from './RepeaterCards.tsx';
import { RepeaterTable } from './RepeaterTable.tsx';
import { useRowKeys } from './useRowKeys.ts';

function bound(node: ElementNode, key: 'minItems' | 'maxItems'): number | null {
    const value = node.raw[key];
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null;
}

function rowsOf(value: JsonValue | undefined): readonly JsonValue[] {
    return Array.isArray(value) ? (value as readonly JsonValue[]) : [];
}

export interface RowActions {
    readonly canRemove: boolean;
    readonly remove: (index: number) => void;
    /** Stable React key per row index. */
    readonly keys: readonly string[];
}

export function Repeater({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const context = useRenderer();
    const { messages } = context;
    const path = [...base, node.key];
    const pointer = toPointer(path);
    const id = pointerId(context.idBase, pointer);
    const addButton = useRef<HTMLButtonElement>(null);
    // After a removal "add" takes focus once the re-render enabled it (disabled at maxItems).
    const focusAdd = useRef(false);
    const count = rowCount(getAt(context.data, path), getAt(context.result.data, path));
    const rows = useRowKeys(count);
    const min = bound(node, 'minItems');
    const max = bound(node, 'maxItems');
    const disabled = context.isReadOnly(node, pointer);
    const error = context.visibleError(pointer);
    const label = typeof node.raw['label'] === 'string' ? node.raw['label'] : node.key;
    const addLabel =
        typeof node.raw['addLabel'] === 'string' ? node.raw['addLabel'] : messages.addRow;

    const add = (): void => {
        context.updateValue(path, (current) => [...rowsOf(current), {}]);
        const target = firstInput(node.children);
        if (target !== undefined) {
            const next = toPointer([...path, count, target.key]);
            context.reveal.reveal(next, pointerId(context.idBase, next));
        }
    };
    useEffect(() => {
        if (focusAdd.current) {
            focusAdd.current = false;
            addButton.current?.focus();
        }
    });
    const actions: RowActions = {
        canRemove: !disabled && (min === null || count > min),
        remove: (index) => {
            context.rowRemoved(path, index);
            rows.drop(index);
            context.updateValue(path, (current) =>
                rowsOf(current).filter((_, row) => row !== index),
            );
            context.touch(pointer);
            focusAdd.current = true;
        },
        keys: rows.keys,
    };
    const table = node.raw['display'] === 'table';
    return (
        <div
            id={`${id}-field`}
            role="group"
            aria-labelledby={`${id}-label`}
            aria-describedby={error === undefined ? undefined : `${id}-error`}
            className={`fsr-repeater ${error === undefined ? '' : 'fsr-field--invalid'}`}
        >
            <div id={`${id}-label`} className="fsr-label fsr-repeater__label">
                {label}
                {node.raw['required'] === true && <RequiredMarker text={messages.requiredMarker} />}
            </div>
            {count === 0 && <p className="fsr-muted">{messages.noRows}</p>}
            {count > 0 && table && (
                <RepeaterTable node={node} path={path} count={count} actions={actions} />
            )}
            {count > 0 && !table && (
                <RepeaterCards node={node} path={path} count={count} actions={actions} />
            )}
            {error !== undefined && (
                <p id={`${id}-error`} className="fsr-error">
                    {errorMessage(error, messages, context.locale)}
                </p>
            )}
            {!disabled && (
                <button
                    ref={addButton}
                    id={id}
                    type="button"
                    className="fsr-button fsr-button--secondary fsr-repeater__add"
                    disabled={max !== null && count >= max}
                    onClick={add}
                >
                    {addLabel}
                </button>
            )}
        </div>
    );
}
