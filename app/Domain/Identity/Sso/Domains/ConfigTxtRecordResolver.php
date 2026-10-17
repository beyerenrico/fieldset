<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;

/**
 * Development and CI TXT resolver (ADR 0036): reads `sso.dns.records` (`SSO_DNS_RECORDS`, a JSON object
 * of TXT name => list of values). Names match case-insensitively and without a trailing dot, like DNS.
 */
final readonly class ConfigTxtRecordResolver implements TxtRecordResolver
{
    public function txt(string $name): array
    {
        $wanted = $this->key($name);
        $records = config('sso.dns.records');
        foreach (is_array($records) ? $records : [] as $recordName => $values) {
            if (is_string($recordName) && $this->key($recordName) === $wanted) {
                return array_values(array_filter(is_array($values) ? $values : [$values], is_string(...)));
            }
        }

        return [];
    }

    private function key(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }
}
