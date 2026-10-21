/** heading, paragraph, divider and image (layout elements without data). */
import type { ElementNode } from '@fieldset/fieldset-js';
import { useMemo, type ReactElement } from 'react';
import { useRenderer } from '../context.tsx';
import { renderMarkdown } from '../markdown.ts';

const DATA_IMAGE = /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/;

function text(node: ElementNode, key: string): string {
    const value = node.raw[key];
    return typeof value === 'string' ? value : '';
}

export function Heading({ node }: { readonly node: ElementNode }): ReactElement {
    const level = node.raw['level'];
    const content = text(node, 'text');
    if (level === 1) {
        return <h2 className="fsr-heading fsr-heading--1">{content}</h2>;
    }
    if (level === 3) {
        return <h4 className="fsr-heading fsr-heading--3">{content}</h4>;
    }
    return <h3 className="fsr-heading fsr-heading--2">{content}</h3>;
}

export function Paragraph({ node }: { readonly node: ElementNode }): ReactElement {
    const source = text(node, 'text');
    const html = useMemo(() => renderMarkdown(source), [source]);
    // Safe: markdown-it runs with html disabled, images off and link validation on.
    return <div className="fsr-markdown" dangerouslySetInnerHTML={{ __html: html }} />;
}

export function Divider(): ReactElement {
    return <hr className="fsr-divider" />;
}

export function ImageElement({ node }: { readonly node: ElementNode }): ReactElement {
    const { messages } = useRenderer();
    const src = text(node, 'src');
    const alt = text(node, 'alt');
    if (DATA_IMAGE.test(src)) {
        return <img className="fsr-image" src={src} alt={alt} />;
    }
    return (
        <div
            className="fsr-image-placeholder"
            role="img"
            aria-label={alt === '' ? messages.imagePlaceholder : alt}
        >
            <span aria-hidden="true">{messages.imagePlaceholder}</span>
            {alt !== '' && <span aria-hidden="true">{alt}</span>}
        </div>
    );
}
