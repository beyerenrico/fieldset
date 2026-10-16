<?php

declare(strict_types=1);

namespace App\Domain\Mail\Policies;

/**
 * Document links are bookkeeping records: they are only reachable through their public token
 * route, never through a UI or API listing.
 */
final class DocumentLinkPolicy
{
    public function viewAny(): bool
    {
        return false;
    }

    public function view(): bool
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
