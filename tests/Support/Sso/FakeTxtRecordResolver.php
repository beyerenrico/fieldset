<?php

declare(strict_types=1);

namespace Tests\Support\Sso;

use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Sso\Contracts\TxtRecordResolver;
use App\Domain\Identity\Sso\Domains\TxtLookupFailed;

/**
 * Test double for DNS TXT lookups (ADR 0036): records per name, names whose lookup fails (SERVFAIL),
 * and the names that were queried. Bind with `FakeTxtRecordResolver::bind()`.
 */
final class FakeTxtRecordResolver implements TxtRecordResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    /** @var list<string> */
    public array $failing = [];

    /** @var list<string> */
    public array $queried = [];

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(TxtRecordResolver::class, $fake);

        return $fake;
    }

    /** Publishes the domain's expected verification record (plus any other values). */
    public function publish(SsoDomain $domain, string ...$extra): self
    {
        $this->records[$domain->txtName()] = [...$extra, $domain->txtValue()];

        return $this;
    }

    /** @param  list<string>  $values */
    public function set(string $name, array $values): self
    {
        $this->records[$name] = $values;

        return $this;
    }

    public function forget(string $name): self
    {
        unset($this->records[$name]);

        return $this;
    }

    public function fail(string $name): self
    {
        $this->failing[] = $name;

        return $this;
    }

    public function txt(string $name): array
    {
        $this->queried[] = $name;
        if (in_array($name, $this->failing, true)) {
            throw new TxtLookupFailed($name);
        }

        return $this->records[$name] ?? [];
    }
}
