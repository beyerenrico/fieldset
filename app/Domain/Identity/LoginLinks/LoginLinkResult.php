<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\User;
use SensitiveParameter;

/**
 * What posting a token gave: a consumed link (state Valid, with link and user; for an owner link also the fresh
 * reset-broker token that replaced the URL token) or the refusal's state.
 */
final readonly class LoginLinkResult
{
    private function __construct(
        public LoginLinkState $state,
        public ?LoginLink $link = null,
        public ?User $user = null,
        public ?int $retryAfter = null,
        #[SensitiveParameter] public ?string $resetToken = null,
    ) {}

    public static function consumed(LoginLink $link, User $user, #[SensitiveParameter] ?string $resetToken = null): self
    {
        return new self(LoginLinkState::Valid, $link, $user, resetToken: $resetToken);
    }

    public static function refused(LoginLinkState $state, ?int $retryAfter = null): self
    {
        return new self($state, retryAfter: $retryAfter);
    }

    /** @return array{state: string, link: int|null, user: int|null, retryAfter: int|null, resetToken: string|null} */
    public function __debugInfo(): array
    {
        return [
            'state' => $this->state->value,
            'link' => $this->link?->id,
            'user' => $this->user?->id,
            'retryAfter' => $this->retryAfter,
            'resetToken' => $this->resetToken === null ? null : '[redacted]',
        ];
    }
}
