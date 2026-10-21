/** section and columns (layout containers without data nesting). */
import type { ElementNode } from '@fieldset/fieldset-js';
import type { ReactElement } from 'react';
import type { DataPath } from '../data/path.ts';
import { ElementList } from './ElementView.tsx';

export function Section({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const label = typeof node.raw['label'] === 'string' ? node.raw['label'] : null;
    const help = typeof node.raw['help'] === 'string' ? node.raw['help'] : null;
    const body = (
        <>
            {help !== null && <p className="fsr-help">{help}</p>}
            <div className="fsr-stack">
                <ElementList nodes={node.children} base={base} />
            </div>
        </>
    );
    if (node.raw['collapsible'] === true) {
        return (
            <details className="fsr-section fsr-section--collapsible" open>
                <summary className="fsr-section__title">{label ?? node.key}</summary>
                {body}
            </details>
        );
    }
    return (
        <section className="fsr-section" aria-label={label ?? undefined}>
            {label !== null && <h3 className="fsr-section__title">{label}</h3>}
            {body}
        </section>
    );
}

/** Groups the flattened children by the column whose `elements` hold the same raw objects. */
function columnGroups(node: ElementNode): ElementNode[][] {
    const columns = node.raw['columns'];
    if (!Array.isArray(columns)) {
        return [node.children.slice()];
    }
    return columns.map((column: unknown) => {
        const elements =
            typeof column === 'object' && column !== null
                ? (column as { elements?: unknown }).elements
                : undefined;
        const raws: readonly unknown[] = Array.isArray(elements) ? elements : [];
        return node.children.filter((child) => raws.includes(child.raw));
    });
}

export function Columns({
    node,
    base,
}: {
    readonly node: ElementNode;
    readonly base: DataPath;
}): ReactElement {
    const groups = columnGroups(node);
    return (
        <div className={`fsr-columns fsr-columns--${String(groups.length)}`}>
            {groups.map((children, index) => (
                <div key={index} className="fsr-column fsr-stack">
                    <ElementList nodes={children} base={base} />
                </div>
            ))}
        </div>
    );
}
