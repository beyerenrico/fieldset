<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\User;

/** What posting a token gave: a consumed link (state Valid, with link and user) or the refusal's state. */
final readonly class LoginLinkResult
{
    private function __construct(
        public LoginLinkState $state,
        public ?LoginLink $link = null,
        public ?User $user = null,
        public ?int $retryAfter = null,
    ) {}

    public static function consumed(LoginLink $link, User $user): self
    {
        return new self(LoginLinkState::Valid, $link, $user);
    }

    public static function refused(LoginLinkState $state, ?int $retryAfter = null): self
    {
        return new self($state, retryAfter: $retryAfter);
    }
}
