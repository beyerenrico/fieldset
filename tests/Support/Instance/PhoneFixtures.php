<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\RenderedProject;
use App\Domain\Instance\State\InstanceState;

/** Local-CA and quick-tunnel states for the phone-HTTPS tests (plan U14, ADR 0082), on top of ComposeFixtures. */
final class PhoneFixtures
{
    public const string LAN = '192.168.1.20';

    public const string EDGE_SUBNET = '10.213.4.32/28';

    public const string EDGE_ADDRESS = '10.213.4.34';

    public const string TUNNEL_SUBNET = '10.215.7.16/28';

    /** cloudflared's fixed address in TUNNEL_SUBNET. */
    public const string PEER = '10.215.7.18';

    public const string HOSTNAME = 'quiet-river-1234.trycloudflare.com';

    /** @param  array<string, string|int|bool|null>  $settings */
    public static function localCa(array $settings = []): InstanceState
    {
        return ComposeFixtures::state(['phone_https' => 'local-ca', 'lan_address' => self::LAN, 'edge_subnet' => self::EDGE_SUBNET, ...$settings])
            ->withUrl('https://'.self::LAN);
    }

    /** @param  array<string, string|int|bool|null>  $settings */
    public static function tunnel(array $settings = []): InstanceState
    {
        return ComposeFixtures::state(['phone_https' => 'tunnel', 'tunnel_subnet' => self::TUNNEL_SUBNET, ...$settings]);
    }

    public static function render(InstanceState $state): RenderedProject
    {
        return (new ComposeRenderer)->render($state, ComposeFixtures::secrets());
    }

    /** @return array{hostname: string, expires_at: string, peer: string, admin: bool} */
    public static function tunnelBlock(string $expiresAt = '2099-01-01T00:00:00Z', bool $admin = false): array
    {
        return ['hostname' => self::HOSTNAME, 'expires_at' => $expiresAt, 'peer' => self::PEER, 'admin' => $admin];
    }
}
