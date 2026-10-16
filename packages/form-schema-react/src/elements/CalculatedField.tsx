/** calculated: read-only output of the value computed by evaluate(). */
import type { ElementNode } from '@form-schema-app/form-schema-js';
import { useContext, type ReactElement } from 'react';
import { CellContext, useRenderer } from '../context.tsx';
import { isDecimalString } from '../data/decimal-input.ts';
import { getAt, pointerId, toPointer, type DataPath } from '../data/path.ts';
import { formatDecimal, formatMoney } from '../format/intl.ts';

export function formatCalculatedValue(
    node: ElementNode,
    value: unknown,
    locale: string,
    empty: string,
): string {
    if (value === null || value === undefined) {
        return empty;
    }
    const format = node.raw['format'];
    const currency = node.raw['currency'];
    if (format !== 'text' && isDecimalString(value)) {
        return format === 'money' && typeof currency === 'string'
            ? formatMoney(value, currency, locale)
            : formatDecimal(value, locale);
    }
    return typeof value === 'string' ? value : JSON.stringify(value);
}

export function CalculatedField({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const context = useRenderer();
    const inCell = useContext(CellContext);
    const path = [...base, node.key];
    const id = pointerId(context.idBase, toPointer(path));
    const label = typeof node.raw['label'] === 'string' ? node.raw['label'] : node.key;
    const help = typeof node.raw['help'] === 'string' ? node.raw['help'] : undefined;
    const value = getAt(context.result.data, path);
    return (
        <div id={`${id}-field`} className="fsr-field fsr-field--calculated">
            <span id={`${id}-label`} className={inCell ? 'fsr-label fsr-sr-only' : 'fsr-label'}>
                {label}
            </span>
            <output
                id={id}
                className="fsr-output"
                aria-labelledby={`${id}-label`}
                aria-live="polite"
            >
                {formatCalculatedValue(node, value, context.locale, context.messages.empty)}
            </output>
            {help !== undefined && <p className="fsr-help">{help}</p>}
        </div>
    );
}
