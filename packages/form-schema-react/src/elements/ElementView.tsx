/** Dispatches an element instance to its component; invisible instances render nothing. */
import type { ElementNode } from '@form-schema-app/form-schema-js';
import type { ReactElement } from 'react';
import { useRenderer } from '../context.tsx';
import { toPointer, type DataPath } from '../data/path.ts';
import { Repeater } from '../repeater/Repeater.tsx';
import { AttachmentField } from './AttachmentField.tsx';
import { BooleanField, RatingField } from './BooleanFields.tsx';
import { CalculatedField } from './CalculatedField.tsx';
import { ChoiceField } from './ChoiceFields.tsx';
import { DateField } from './DateFields.tsx';
import { DecimalField } from './DecimalField.tsx';
import { LocationField } from './LocationField.tsx';
import { Columns, Section } from './Containers.tsx';
import { SignatureField } from './SignatureField.tsx';
import { Divider, Heading, ImageElement, Paragraph } from './StaticElements.tsx';
import { TextField } from './TextField.tsx';

export interface ElementProps {
    readonly node: ElementNode;
    readonly base: DataPath;
}

/** Renders the component for a type (a switch keeps the import cycle with containers safe). */
function renderElement(node: ElementNode, base: DataPath): ReactElement | null {
    switch (node.type) {
        case 'text':
        case 'textarea':
        case 'email':
        case 'phone':
            return <TextField node={node} base={base} />;
        case 'number':
        case 'money':
            return <DecimalField node={node} base={base} />;
        case 'date':
        case 'time':
        case 'datetime':
            return <DateField node={node} base={base} />;
        case 'select':
        case 'radio':
        case 'multiselect':
            return <ChoiceField node={node} base={base} />;
        case 'checkbox':
        case 'toggle':
            return <BooleanField node={node} base={base} />;
        case 'rating':
            return <RatingField node={node} base={base} />;
        case 'photo':
        case 'file':
            return <AttachmentField node={node} base={base} />;
        case 'signature':
            return <SignatureField node={node} base={base} />;
        case 'location':
            return <LocationField node={node} base={base} />;
        case 'calculated':
            return <CalculatedField node={node} base={base} />;
        case 'repeater':
            return <Repeater node={node} base={base} />;
        case 'section':
            return <Section node={node} base={base} />;
        case 'columns':
            return <Columns node={node} base={base} />;
        case 'heading':
            return <Heading node={node} />;
        case 'paragraph':
            return <Paragraph node={node} />;
        case 'divider':
            return <Divider />;
        case 'image':
            return <ImageElement node={node} />;
        default:
            // hidden, page_break and unknown types render nothing.
            return null;
    }
}

export function ElementView({ node, base }: ElementProps): ReactElement | null {
    const { result } = useRenderer();
    if (result.visibility[toPointer([...base, node.key])] !== true) {
        return null;
    }
    return renderElement(node, base);
}

export function ElementList({
    nodes,
    base,
}: {
    readonly nodes: readonly ElementNode[];
    readonly base: DataPath;
}): ReactElement {
    return (
        <>
            {nodes.map((node) => (
                <ElementView key={node.id === '' ? node.path : node.id} node={node} base={base} />
            ))}
        </>
    );
}
