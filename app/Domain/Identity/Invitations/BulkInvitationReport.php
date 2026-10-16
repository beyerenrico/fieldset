<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

/** Preview of a member CSV: every row with its result (M3-T11). */
final readonly class BulkInvitationReport
{
    /** @param  list<PlannedInvitation>  $rows */
    public function __construct(public array $rows) {}

    /** @return list<PlannedInvitation> */
    public function valid(): array
    {
        return array_values(array_filter($this->rows, fn (PlannedInvitation $row): bool => $row->isValid()));
    }

    /** @return list<PlannedInvitation> */
    public function invalid(): array
    {
        return array_values(array_filter($this->rows, fn (PlannedInvitation $row): bool => ! $row->isValid()));
    }
}
