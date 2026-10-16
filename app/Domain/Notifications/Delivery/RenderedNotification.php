<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Delivery;

/**
 * A notification rendered for one recipient: plain text in their locale (never HTML; every channel
 * escapes on output) and a safe link (relative path or http(s) URL) or none.
 */
final readonly class RenderedNotification
{
    public function __construct(
        public string $title,
        public string $body,
        public string $locale,
        public ?string $url,
    ) {}

    /** The link as an absolute URL (mail buttons); relative paths resolve against APP_URL. */
    public function absoluteUrl(): ?string
    {
        if ($this->url === null) {
            return null;
        }

        return str_starts_with($this->url, '/') ? url($this->url) : $this->url;
    }
}
