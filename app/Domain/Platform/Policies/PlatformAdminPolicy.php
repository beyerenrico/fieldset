<?php

declare(strict_types=1);

namespace App\Domain\Platform\Policies;

/** Operator grants are managed on the command line only (`app:platform-admin`): no UI or API access. */
final class PlatformAdminPolicy
{
    public function viewAny(): bool
    {
        return false;
    }

    public function view(): bool
    {
        return false;
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }
}
