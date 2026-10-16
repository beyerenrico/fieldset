<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Support\Network\HostResolver;

/** DNS for tests: host name → addresses; unknown hosts resolve to a public documentation-free address. */
final class FakeHostResolver implements HostResolver
{
    /** @var list<string> */
    public array $lookups = [];

    /** @param  array<string, list<string>>  $hosts */
    public function __construct(private readonly array $hosts = []) {}

    public static function bind(array $hosts = []): self
    {
        $fake = new self($hosts);
        app()->instance(HostResolver::class, $fake);

        return $fake;
    }

    public function resolve(string $host): array
    {
        $host = trim($host, '[]');
        $this->lookups[] = $host;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[$host] ?? ['93.184.215.14'];
    }
}
