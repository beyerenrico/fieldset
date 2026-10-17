<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;

/**
 * Production TXT resolver (ADR 0036): dns_get_record through the system resolver. NXDOMAIN and NODATA
 * are an empty list; a failed lookup (SERVFAIL, timeout) throws TxtLookupFailed. Multi-string records
 * are joined, as DNS defines them.
 */
final readonly class DnsTxtRecordResolver implements TxtRecordResolver
{
    /** @throws TxtLookupFailed */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if ($records === false) {
            throw new TxtLookupFailed($name);
        }

        $values = [];
        foreach ($records as $record) {
            $entries = $record['entries'] ?? null;
            $value = is_array($entries) ? implode('', array_map(strval(...), $entries)) : ($record['txt'] ?? null);
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
