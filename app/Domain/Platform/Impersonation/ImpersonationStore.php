<?php

declare(strict_types=1);

namespace App\Domain\Platform\Impersonation;

use Illuminate\Contracts\Session\Session;

/** The current impersonation of this session (server-side session data only, never a cookie value). */
final readonly class ImpersonationStore
{
    public const string KEY = 'platform.impersonation';

    public function __construct(private Session $session) {}

    public function current(): ?Impersonation
    {
        return Impersonation::fromArray($this->session->get(self::KEY));
    }

    public function active(): bool
    {
        return $this->current() instanceof Impersonation;
    }

    public function put(Impersonation $impersonation): void
    {
        $this->session->put(self::KEY, $impersonation->toArray());
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
