<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Doctor\DoctorProbes;

/** DoctorProbes without DNS, network or root-owned files. */
final class FakeDoctorProbes extends DoctorProbes
{
    /** @var array<string, array{a: list<string>, aaaa: list<string>}> */
    public array $dns = [];

    /** @var array{status: int, date: string|null}|null */
    public ?array $registry = ['status' => 401, 'date' => null];

    public ?float $free = 50.0 * 1024 ** 3;

    /** @var array<string, array{uid: int, mode: int}> */
    public array $stats = [];

    /** @var list<string> */
    public array $heads = [];

    public function resolve(string $host, int $type): array
    {
        return $this->dns[$host][$type === DNS_AAAA ? 'aaaa' : 'a'] ?? [];
    }

    public function head(string $url, float $timeoutSeconds = 5.0): ?array
    {
        $this->heads[] = $url;

        return $this->registry;
    }

    public function diskFree(string $path): ?float
    {
        return $this->free;
    }

    public function stat(string $path): ?array
    {
        return $this->stats[$path] ?? parent::stat($path);
    }
}
