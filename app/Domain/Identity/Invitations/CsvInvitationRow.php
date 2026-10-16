<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

/** One data row of a member CSV as written in the file (not validated yet). */
final readonly class CsvInvitationRow
{
    /**
     * @param  list<string>  $roles  role tokens (names or labels)
     * @param  list<string>  $groups  group names
     */
    public function __construct(
        public int $line,
        public string $email,
        public string $name,
        public array $roles,
        public array $groups,
    ) {}
}
