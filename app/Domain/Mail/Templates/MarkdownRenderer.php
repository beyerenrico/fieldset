<?php

declare(strict_types=1);

namespace App\Domain\Mail\Templates;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Converts tenant-authored Markdown mail bodies to HTML. Raw HTML in the template is escaped and
 * unsafe link schemes (javascript:, data:, …) are dropped, so tenants cannot inject markup.
 */
final class MarkdownRenderer
{
    private ?MarkdownConverter $converter = null;

    public function toHtml(string $markdown): string
    {
        return $this->converter()->convert($markdown)->getContent();
    }

    private function converter(): MarkdownConverter
    {
        if (! $this->converter instanceof MarkdownConverter) {
            $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }
}
