import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { definition, el, page } from './fixtures/definitions.ts';
import { renderForm } from './helpers.tsx';

const PNG =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

const layoutForm = definition([
    page('p', [
        el({ type: 'heading', key: 'h1', text: 'Big', level: 1 }),
        el({ type: 'heading', key: 'h2', text: 'Medium' }),
        el({ type: 'heading', key: 'h3', text: 'Small', level: 3 }),
        el({
            type: 'paragraph',
            key: 'para',
            text: 'Hello **bold** <script>alert(1)</script> [site](https://example.com) [bad](javascript:alert(1)) ![img](https://example.com/x.png)',
        }),
        el({ type: 'divider', key: 'div' }),
        el({ type: 'image', key: 'img1', src: PNG, alt: 'Logo' }),
        el({
            type: 'image',
            key: 'img2',
            src: 'asset:0b5f1c1e-1111-4111-8111-111111111111',
            alt: 'Plan',
        }),
        el({ type: 'image', key: 'img3', src: 'https://example.com/remote.png' }),
        el({ type: 'page_break', key: 'pb' }),
        el({
            type: 'section',
            key: 'sec',
            label: 'Customer',
            help: 'Who ordered',
            elements: [el({ type: 'text', key: 'customer', label: 'Customer name' })],
        }),
        el({
            type: 'section',
            key: 'fold',
            label: 'More',
            collapsible: true,
            elements: [el({ type: 'text', key: 'extra', label: 'Extra' })],
        }),
        el({
            type: 'columns',
            key: 'cols',
            columns: [
                { elements: [el({ type: 'text', key: 'left', label: 'Left' })] },
                {
                    elements: [
                        el({ type: 'text', key: 'right', label: 'Right' }),
                        el({ type: 'text', key: 'right2', label: 'Right 2' }),
                    ],
                },
            ],
        }),
    ]),
]);

describe('layout elements', () => {
    it('maps heading levels to h2–h4', () => {
        renderForm(layoutForm);
        expect(screen.getByRole('heading', { name: 'Big', level: 2 })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Medium', level: 3 })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Small', level: 4 })).toBeInTheDocument();
    });

    it('renders sanitized Markdown', () => {
        const { container } = renderForm(layoutForm);
        const markdown = container.querySelector('.fsr-markdown');
        expect(markdown?.querySelector('strong')).toHaveTextContent('bold');
        expect(markdown?.querySelector('script')).toBeNull();
        expect(markdown).toHaveTextContent('<script>alert(1)</script>');
        expect(markdown?.querySelector('img')).toBeNull();
        const link = within(markdown as HTMLElement).getByRole('link', { name: 'site' });
        expect(link).toHaveAttribute('href', 'https://example.com');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow');
        expect(markdown?.querySelector('a[href^="javascript"]')).toBeNull();
    });

    it('renders dividers, data URI images and placeholders for other sources', () => {
        const { container } = renderForm(layoutForm);
        expect(container.querySelector('hr.fsr-divider')).not.toBeNull();
        expect(screen.getByRole('img', { name: 'Logo' })).toHaveAttribute('src', PNG);
        expect(screen.getByRole('img', { name: 'Plan' })).toHaveClass('fsr-image-placeholder');
        const remote = screen.getByRole('img', { name: 'Image' });
        expect(remote.tagName).toBe('DIV');
        expect(container.querySelector('img[src^="https"]')).toBeNull();
    });

    it('renders sections, collapsible sections and columns', async () => {
        const { user, lastData, container } = renderForm(layoutForm);
        const section = screen.getByRole('region', { name: 'Customer' });
        expect(within(section).getByText('Who ordered')).toBeInTheDocument();
        await user.type(within(section).getByLabelText('Customer name'), 'ACME');
        expect(lastData()).toMatchObject({ customer: 'ACME' });
        const details = container.querySelector('details');
        expect(details).toHaveAttribute('open');
        expect(within(details as HTMLElement).getByText('More').tagName).toBe('SUMMARY');
        const columns = container.querySelectorAll('.fsr-columns--2 > .fsr-column');
        expect(columns).toHaveLength(2);
        expect(within(columns[1] as HTMLElement).getAllByRole('textbox')).toHaveLength(2);
    });
});
