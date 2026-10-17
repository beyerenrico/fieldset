<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;

/** Skeleton stub (M7, owner B): production resolver (dns_get_record); finds nothing until implemented. */
final readonly class DnsTxtRecordResolver implements TxtRecordResolver
{
    public function txt(string $name): array
    {
        return [];
    }
}
