<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

/** A validated CSV row: what would be sent, or why it cannot be. Errors and notices are translated. */
final readonly class PlannedInvitation
{
    /**
     * @param  list<string>  $roles  role names
     * @param  list<int>  $groupIds
     * @param  list<string>  $errors
     * @param  list<string>  $notices
     */
    public function __construct(
        public int $line,
        public string $email,
        public string $name,
        public array $roles,
        public array $groupIds,
        public array $errors,
        public array $notices = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
