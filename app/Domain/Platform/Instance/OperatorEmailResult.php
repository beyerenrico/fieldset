<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\Models\User;

/** What `SetOperatorEmail` did: the account, whether it was created or newly granted, who lost the role, the link. */
final readonly class OperatorEmailResult
{
    /** @param  list<string>  $revoked */
    public function __construct(
        public User $user,
        public bool $created,
        public bool $granted,
        public array $revoked,
        public ?IssuedLoginLink $link,
    ) {}

    public function changed(): bool
    {
        return $this->created || $this->granted || $this->revoked !== [] || $this->link instanceof IssuedLoginLink;
    }
}
