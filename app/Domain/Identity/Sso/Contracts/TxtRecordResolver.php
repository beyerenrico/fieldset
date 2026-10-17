<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Contracts;

/** DNS TXT lookup for domain verification: dns_get_record in production, config-backed in dev/CI, a fake in tests. */
interface TxtRecordResolver
{
    /** @return list<string> */
    public function txt(string $name): array;
}
