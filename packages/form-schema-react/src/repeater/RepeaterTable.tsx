/** Repeater rows as a horizontally scrollable table; labels move into the column headers. */
import type { ReactElement } from 'react';
import { CellContext, useRenderer } from '../context.tsx';
import { pointerId, toPointer } from '../data/path.ts';
import { ElementView } from '../elements/ElementView.tsx';
import { interpolate } from '../i18n/messages.ts';
import { flattenColumns } from '../structure/instances.ts';
import type { RowsProps } from './RepeaterCards.tsx';
import { rowLabel } from './row-label.ts';
import { useRowWindow } from './useRowWindow.ts';

export function RepeaterTable({ node, path, count, actions }: RowsProps): ReactElement {
    const { messages, idBase } = useRenderer();
    const pointer = toPointer(path);
    const { indexes, before, after, measure, attachList } = useRowWindow(count, pointer, 64);
    const columns = flattenColumns(node.children);
    const span = columns.length + 2;
    const headId = pointerId(idBase, pointer);
    return (
        <div
            className="fsr-table-wrap"
            role="region"
            aria-labelledby={`${headId}-label`}
            tabIndex={0}
        >
            <table className="fsr-table">
                <thead>
                    <tr>
                        <th scope="col" className="fsr-table__index">
                            <span className="fsr-sr-only">#</span>
                        </th>
                        {columns.map((column) => (
                            <th key={column.id === '' ? column.path : column.id} scope="col">
                                {typeof column.raw['label'] === 'string'
                                    ? column.raw['label']
                                    : column.key}
                            </th>
                        ))}
                        <th scope="col">
                            <span className="fsr-sr-only">
                                {messages.removeRow.replace(/\s*\{index\}\s*/, ' ').trim()}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody ref={attachList}>
                    {before > 0 && (
                        <tr aria-hidden="true" className="fsr-spacer" style={{ height: before }}>
                            <td colSpan={span} />
                        </tr>
                    )}
                    {indexes.map((index) => (
                        <tr key={index} ref={measure} data-index={index}>
                            <th scope="row" className="fsr-table__index">
                                <span aria-hidden="true">{index + 1}</span>
                                <span className="fsr-sr-only">
                                    {rowLabel(node, index, messages)}
                                </span>
                            </th>
                            <CellContext.Provider value>
                                {columns.map((column) => (
                                    <td key={column.id === '' ? column.path : column.id}>
                                        <ElementView node={column} base={[...path, index]} />
                                    </td>
                                ))}
                            </CellContext.Provider>
                            <td>
                                {actions.canRemove && (
                                    <button
                                        type="button"
                                        className="fsr-button fsr-button--quiet"
                                        aria-label={interpolate(messages.removeRow, {
                                            index: index + 1,
                                        })}
                                        onClick={() => {
                                            actions.remove(index);
                                        }}
                                    >
                                        ✕
                                    </button>
                                )}
                            </td>
                        </tr>
                    ))}
                    {after > 0 && (
                        <tr aria-hidden="true" className="fsr-spacer" style={{ height: after }}>
                            <td colSpan={span} />
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
