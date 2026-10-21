<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\RenderedProject;
use App\Domain\Instance\State\InstanceState;

/** Domain-mode states for the edge tests (plan U8, ADR 0075), on top of ComposeFixtures. */
final class DomainFixtures
{
    public const string DOMAIN = 'forms.example.com';

    public const string SUBNET = '10.213.4.32/28';

    /** The edge's fixed address in SUBNET. */
    public const string EDGE_ADDRESS = '10.213.4.34';

    /** @param  array<string, string|int|bool|null>  $settings */
    public static function state(array $settings = [], string $path = '/srv/fieldset'): InstanceState
    {
        return ComposeFixtures::state(['domain' => self::DOMAIN, 'edge_subnet' => self::SUBNET, ...$settings], $path)
            ->withMode('domain')
            ->withUrl('https://'.self::DOMAIN);
    }

    /** @param  array<string, string|int|bool|null>  $settings */
    public static function render(array $settings = []): RenderedProject
    {
        return (new ComposeRenderer)->render(self::state($settings), ComposeFixtures::secrets());
    }
}
