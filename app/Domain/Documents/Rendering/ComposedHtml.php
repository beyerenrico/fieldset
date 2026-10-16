<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

/** The three HTML documents sent to Gotenberg: body, header and footer (all assets inlined). */
final readonly class ComposedHtml
{
    public function __construct(public string $body, public string $header, public string $footer) {}

    public function bytes(): int
    {
        return strlen($this->body) + strlen($this->header) + strlen($this->footer);
    }

    /** @return array{body: string, header: string, footer: string} sha256 of each part */
    public function hashes(): array
    {
        return [
            'body' => hash('sha256', $this->body),
            'header' => hash('sha256', $this->header),
            'footer' => hash('sha256', $this->footer),
        ];
    }
}
