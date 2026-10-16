<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allow-list sanitizer for tenant-authored rich text (design header/footer) and rendered paragraph
 * Markdown. Tenants never author executable templates (§8.5 Security): only inline formatting, lists
 * and headings survive; links keep their text, images, scripts, styles and URLs are removed. The only
 * attribute kept is `style="text-align: …"` from the rich editor.
 */
final class RichTextSanitizer
{
    private const array TEXT_ELEMENTS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'span', 'sub', 'sup', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'hr'];

    private const array ALIGNABLE = ['p', 'h1', 'h2', 'h3', 'h4'];

    private const array BLOCKED = ['a', 'div', 'font', 'section', 'article', 'header', 'footer', 'mark', 'h5', 'h6', 'table', 'tbody', 'thead', 'tr', 'td', 'th'];

    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        $clean = $this->sanitizer()->sanitize($html);

        return (string) preg_replace_callback('/ style="([^"]*)"/', static fn (array $m): string => preg_match('/text-align:\s*(left|center|right|justify|start|end)/', $m[1], $align) === 1
            ? ' style="text-align: '.$align[1].'"'
            : '', $clean);
    }

    /** Spec paragraphs are Markdown without raw HTML (spec §3.2): render, then sanitize. */
    public function markdown(string $markdown): string
    {
        return $this->sanitize(Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]));
    }

    private function sanitizer(): HtmlSanitizer
    {
        if (! $this->sanitizer instanceof HtmlSanitizer) {
            $config = (new HtmlSanitizerConfig)->withMaxInputLength(50_000);
            foreach (self::TEXT_ELEMENTS as $element) {
                $config = $config->allowElement($element);
            }
            foreach (self::ALIGNABLE as $element) {
                $config = $config->allowElement($element, ['style']);
            }
            foreach (self::BLOCKED as $element) {
                $config = $config->blockElement($element);
            }
            $this->sanitizer = new HtmlSanitizer($config);
        }

        return $this->sanitizer;
    }
}
