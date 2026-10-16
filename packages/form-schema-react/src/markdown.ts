/** Sanitized Markdown for `paragraph` elements: raw HTML disabled, no images, safe links. */
import MarkdownIt from 'markdown-it';

const md = new MarkdownIt({ html: false, linkify: false, typographer: false });
md.disable('image');

const defaultLinkOpen = md.renderer.rules.link_open;

md.renderer.rules.link_open = (tokens, index, options, env, self) => {
    const token = tokens[index];
    if (token !== undefined) {
        token.attrSet('target', '_blank');
        token.attrSet('rel', 'noopener noreferrer nofollow');
    }
    return defaultLinkOpen === undefined
        ? self.renderToken(tokens, index, options)
        : defaultLinkOpen(tokens, index, options, env, self);
};

/** Renders Markdown to HTML. Raw HTML is escaped; `javascript:` and similar links are dropped. */
export function renderMarkdown(text: string): string {
    return md.render(text);
}
