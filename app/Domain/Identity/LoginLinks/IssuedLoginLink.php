<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

use App\Domain\Identity\Models\LoginLink;
use SensitiveParameter;

/** A freshly issued link: the URL (token in the fragment) exists only here and is shown once. */
final readonly class IssuedLoginLink
{
    public function __construct(public LoginLink $link, #[SensitiveParameter] public string $url) {}

    /** @return array{link: int, url: string} */
    public function __debugInfo(): array
    {
        return ['link' => $this->link->id, 'url' => '[redacted]'];
    }
}
