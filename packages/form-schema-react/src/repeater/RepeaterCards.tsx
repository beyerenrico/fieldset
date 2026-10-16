/** Repeater rows as cards (fieldset per row). */
import type { ElementNode } from '@form-schema-app/form-schema-js';
import type { ReactElement } from 'react';
import { useRenderer } from '../context.tsx';
import { toPointer, type DataPath } from '../data/path.ts';
import { ElementList } from '../elements/ElementView.tsx';
import { interpolate } from '../i18n/messages.ts';
import type { RowActions } from './Repeater.tsx';
import { rowLabel } from './row-label.ts';
import { useRowWindow } from './useRowWindow.ts';

export interface RowsProps {
    readonly node: ElementNode;
    readonly path: DataPath;
    readonly count: number;
    readonly actions: RowActions;
}

export function RepeaterCards({ node, path, count, actions }: RowsProps): ReactElement {
    const { messages } = useRenderer();
    const { indexes, before, after, measure, attachList } = useRowWindow(
        count,
        toPointer(path),
        320,
    );
    return (
        <ol ref={attachList} className="fsr-cards">
            {before > 0 && (
                <li aria-hidden="true" className="fsr-spacer" style={{ height: before }} />
            )}
            {indexes.map((index) => {
                const label = rowLabel(node, index, messages);
                return (
                    <li
                        key={actions.keys[index] ?? index}
                        ref={measure}
                        data-index={index}
                        className="fsr-cards__item"
                    >
                        <fieldset className="fsr-card">
                            <legend className="fsr-card__title">{label}</legend>
                            <div className="fsr-stack">
                                <ElementList nodes={node.children} base={[...path, index]} />
                            </div>
                            {actions.canRemove && (
                                <button
                                    type="button"
                                    className="fsr-button fsr-button--quiet fsr-card__remove"
                                    onClick={() => {
                                        actions.remove(index);
                                    }}
                                >
                                    {interpolate(messages.removeRow, { index: index + 1 })}
                                </button>
                            )}
                        </fieldset>
                    </li>
                );
            })}
            {after > 0 && (
                <li aria-hidden="true" className="fsr-spacer" style={{ height: after }} />
            )}
        </ol>
    );
}
