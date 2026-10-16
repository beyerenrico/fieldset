/** One page: title, description and its elements. */
import type { PageNode } from '@form-schema-app/form-schema-js';
import type { ReactElement, Ref } from 'react';
import { ElementList } from '../elements/ElementView.tsx';

export interface PageViewProps {
    readonly page: PageNode;
    readonly headingRef?: Ref<HTMLHeadingElement>;
    readonly idBase: string;
}

export function PageView({ page, headingRef, idBase }: PageViewProps): ReactElement {
    const title = page.page.title;
    const description = page.page.description;
    const headingId = `${idBase}-page-${String(page.index)}`;
    return (
        <section className="fsr-page" aria-labelledby={headingId}>
            <h2 ref={headingRef} id={headingId} className="fsr-page__title" tabIndex={-1}>
                {title}
            </h2>
            {typeof description === 'string' && description !== '' && (
                <p className="fsr-page__description">{description}</p>
            )}
            <div className="fsr-stack">
                <ElementList nodes={page.elements} base={[]} />
            </div>
        </section>
    );
}
