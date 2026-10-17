<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;

/** Skeleton stub (M7, owner B): dev/CI resolver backed by config('sso.dns.records'); finds nothing until implemented. */
final readonly class ConfigTxtRecordResolver implements TxtRecordResolver
{
    public function txt(string $name): array
    {
        return [];
    }
}
